<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Support\Audit\BusinessAuditEventKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AccountSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: User, 2: string} */
    private function verifiedCompanyAdmin(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Acme Corp', 'status' => Tenant::STATUS_ACTIVE]);
        $password = 'Secret123!';
        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Admin',
            'email' => 'account-ca-'.uniqid('', true).'@example.com',
            'password' => $password,
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        return [$tenant, $user, $password];
    }

    public function test_get_own_profile_includes_enrichment_fields(): void
    {
        [$tenant, $user] = $this->verifiedCompanyAdmin();
        Sanctum::actingAs($user);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Jane Admin')
            ->assertJsonPath('data.user.tenant.id', $tenant->id)
            ->assertJsonPath('data.user.tenant.name', 'Acme Corp')
            ->assertJsonStructure([
                'data' => [
                    'user' => [
                        'last_login_at',
                        'organization',
                        'email_verified_at',
                    ],
                ],
            ]);
    }

    public function test_update_own_profile_name(): void
    {
        [, $user] = $this->verifiedCompanyAdmin();
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/profile', ['name' => 'Naceer Khan'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.name', 'Naceer Khan');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Naceer Khan']);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event_key' => BusinessAuditEventKeys::USERS_PROFILE_UPDATED,
            'module' => 'user',
            'action' => 'profile_updated',
        ]);
    }

    public function test_profile_update_ignores_forbidden_fields(): void
    {
        [, $user] = $this->verifiedCompanyAdmin();
        $originalEmail = $user->email;
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/profile', [
            'name' => 'Updated Name',
            'email' => 'hacker@evil.com',
            'role' => 'global_admin',
            'tenant_id' => 999,
            'status' => 'suspended',
        ])->assertOk();

        $user->refresh();
        $this->assertSame('Updated Name', $user->name);
        $this->assertSame($originalEmail, $user->email);
        $this->assertSame('company_admin', $user->role);
    }

    public function test_inactive_user_cannot_update_profile(): void
    {
        [, $user] = $this->verifiedCompanyAdmin();
        $user->update(['status' => User::STATUS_INACTIVE]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/profile', ['name' => 'Blocked'])
            ->assertStatus(403);
    }

    public function test_change_password_happy_path(): void
    {
        Notification::fake();

        [, $user, $oldPassword] = $this->verifiedCompanyAdmin();
        $token = $user->createToken('web')->plainTextToken;
        $otherTokenId = $user->createToken('mobile')->accessToken->id;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/user/password', [
                'current_password' => $oldPassword,
                'password' => 'NewPass123!',
                'password_confirmation' => 'NewPass123!',
            ]);

        $response->assertOk()->assertJsonPath('message', 'Password changed successfully.');

        $user->refresh();
        $this->assertTrue(Hash::check('NewPass123!', (string) $user->password));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event_key' => BusinessAuditEventKeys::USERS_PASSWORD_CHANGED,
        ]);

        $audit = AuditLog::query()
            ->where('event_key', BusinessAuditEventKeys::USERS_PASSWORD_CHANGED)
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $encoded = json_encode([
            $audit->before,
            $audit->after,
            $audit->metadata,
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsStringIgnoringCase('NewPass123!', $encoded);
        $this->assertStringNotContainsStringIgnoringCase('Secret123!', $encoded);

        Notification::assertSentTo($user, PasswordChangedNotification::class);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherTokenId]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertOk();
    }

    public function test_change_password_wrong_current_password(): void
    {
        [, $user, $oldPassword] = $this->verifiedCompanyAdmin();
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/password', [
            'current_password' => 'wrong-password',
            'password' => 'NewPass123!',
            'password_confirmation' => 'NewPass123!',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_change_password_same_as_current_rejected(): void
    {
        [, $user, $oldPassword] = $this->verifiedCompanyAdmin();
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/password', [
            'current_password' => $oldPassword,
            'password' => $oldPassword,
            'password_confirmation' => $oldPassword,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_change_password_confirmation_mismatch(): void
    {
        [, $user, $oldPassword] = $this->verifiedCompanyAdmin();
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/password', [
            'current_password' => $oldPassword,
            'password' => 'NewPass123!',
            'password_confirmation' => 'Mismatch123!',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_change_password_rate_limit(): void
    {
        [, $user, $oldPassword] = $this->verifiedCompanyAdmin();
        Sanctum::actingAs($user);
        RateLimiter::clear('change-password|'.$user->id);

        for ($i = 0; $i < 5; $i++) {
            $this->patchJson('/api/user/password', [
                'current_password' => 'wrong',
                'password' => 'NewPass123!',
                'password_confirmation' => 'NewPass123!',
            ]);
        }

        $this->patchJson('/api/user/password', [
            'current_password' => $oldPassword,
            'password' => 'NewPass123!',
            'password_confirmation' => 'NewPass123!',
        ])->assertStatus(429);
    }

    public function test_regression_login_and_forgot_password_still_work(): void
    {
        Notification::fake();

        [, $user, $password] = $this->verifiedCompanyAdmin();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk();

        $this->postJson('/api/forgot-password', [
            'email' => $user->email,
        ])->assertOk();
    }
}
