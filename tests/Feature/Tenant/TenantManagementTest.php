<?php

namespace Tests\Feature\Tenant;

use App\Models\Tenant;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_user_defaults_to_normal_user_role(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'company_name' => 'Acme Inc',
            'email' => 'john-role@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', [
            'email' => 'john-role@example.com',
            'role' => 'user',
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_company_admin_creates_user_in_same_tenant(): void
    {
        Notification::fake();

        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $companyAdmin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'company-admin@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $team = Team::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Sales Team',
            'status' => 1,
        ]);

        Sanctum::actingAs($companyAdmin);

        $response = $this->postJson('/api/users', [
            'name' => 'Tenant User',
            'email' => 'tenant-user@example.com',
            'password' => 'secret123',
            'team_id' => $team->id,
            'data_scope' => 'team',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', [
            'email' => 'tenant-user@example.com',
            'tenant_id' => $tenant->id,
            'role' => 'user',
            'team_id' => $team->id,
            'data_scope' => 2,
        ]);

        $createdUser = User::query()->where('email', 'tenant-user@example.com')->firstOrFail();
        Notification::assertSentTo($createdUser, VerifyEmailNotification::class);
    }

    public function test_company_admin_cannot_create_company_admin_role_user(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $companyAdmin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'company-admin2@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($companyAdmin);

        $response = $this->postJson('/api/users', [
            'name' => 'Another Admin',
            'email' => 'another-admin@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_tenant_isolation_limits_company_admin_user_list_to_own_tenant(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'status' => Tenant::STATUS_ACTIVE]);

        $companyAdmin = User::query()->create([
            'tenant_id' => $tenantA->id,
            'name' => 'Admin',
            'email' => 'company-admin3@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        User::query()->create([
            'tenant_id' => $tenantA->id,
            'name' => 'Tenant A User',
            'email' => 'tenant-a-user@example.com',
            'password' => 'secret123',
            'role' => 'user',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        User::query()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Tenant B User',
            'email' => 'tenant-b-user@example.com',
            'password' => 'secret123',
            'role' => 'user',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($companyAdmin);

        $response = $this->getJson('/api/users');

        $response->assertOk();
        $emails = collect($response->json('data.items'))->pluck('email');
        $this->assertTrue($emails->contains('tenant-a-user@example.com'));
        $this->assertFalse($emails->contains('tenant-b-user@example.com'));
    }

    public function test_global_admin_can_view_all_tenants_and_promote_user(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $globalAdmin = User::query()->create([
            'tenant_id' => null,
            'name' => 'Global Admin',
            'email' => 'global-admin@example.com',
            'password' => 'secret123',
            'role' => 'global_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $targetUser = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Target User',
            'email' => 'target-user@example.com',
            'password' => 'secret123',
            'role' => 'user',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($globalAdmin);

        $this->getJson('/api/tenants')->assertOk();

        $this->patchJson("/api/users/{$targetUser->id}/role", [
            'role' => 'company_admin',
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $targetUser->id,
            'role' => 'company_admin',
        ]);
    }
}
