<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_tenant_and_user_and_sends_verification_email(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/register', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'company_name' => 'Acme Inc',
            'email' => 'john@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('tenants', ['name' => 'Acme Inc']);
        $this->assertDatabaseHas('users', ['email' => 'john@example.com', 'name' => 'John Doe']);

        $user = User::query()->where('email', 'john@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_login_is_blocked_until_email_is_verified(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Acme Inc', 'status' => Tenant::STATUS_ACTIVE]);
        User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret123',
            'email_verified_at' => null,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'jane@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('errors.email.0', 'Please verify your email address before logging in.');
    }

    public function test_email_verification_then_login_returns_token(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Acme Inc', 'status' => Tenant::STATUS_ACTIVE]);
        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret123',
            'email_verified_at' => null,
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1($user->email),
            ]
        );

        $this->getJson($verificationUrl)
            ->assertOk()
            ->assertJsonPath('success', true);

        $loginResponse = $this->postJson('/api/login', [
            'email' => 'jane@example.com',
            'password' => 'secret123',
        ]);

        $loginResponse->assertOk();
        $loginResponse->assertJsonPath('success', true);
        $this->assertNotEmpty($loginResponse->json('data.token'));
    }

    public function test_authenticated_user_endpoint_and_logout_work(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Acme Inc', 'status' => Tenant::STATUS_ACTIVE]);
        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret123',
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'jane@example.com');

        $this->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_forgot_password_sends_reset_mail_for_existing_user(): void
    {
        Notification::fake();

        $tenant = Tenant::query()->create(['name' => 'Acme Inc', 'status' => Tenant::STATUS_ACTIVE]);
        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret123',
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/forgot-password', [
            'email' => 'jane@example.com',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }
}
