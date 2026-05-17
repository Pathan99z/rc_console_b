<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\Notifications\UserAccessRevoked;
use App\Models\Role;
use App\Models\User;
use App\Support\Notifications\InAppNotificationTemplateKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Concerns\BuildsTenantUsersForNotifications;
use Tests\TestCase;

final class UserNotificationTest extends TestCase
{
    use BuildsTenantUsersForNotifications;
    use RefreshDatabase;

    public function test_admin_create_user_writes_users_invited(): void
    {
        $tenant = $this->makeActiveTenant();
        $admin = $this->makeCompanyAdmin($tenant);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/users', [
            'name' => 'New Hire',
            'email' => 'hire-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
        ]);
        $response->assertCreated();
        $uid = (int) $response->json('data.user.id');

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $uid,
            'notification_type' => InAppNotificationTemplateKeys::USERS_INVITED,
        ]);
    }

    public function test_global_admin_role_change_writes_users_role_changed(): void
    {
        $tenant = $this->makeActiveTenant();
        $target = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Role Victim',
            'email' => 'rv-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_USER,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $global = $this->makeGlobalAdmin();
        Sanctum::actingAs($global);

        $this->patchJson("/api/users/{$target->id}/role", [
            'role' => Role::CODE_COMPANY_ADMIN,
        ])->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $target->id,
            'notification_type' => InAppNotificationTemplateKeys::USERS_ROLE_CHANGED,
        ]);
    }

    public function test_status_deprecation_dispatches_user_access_revoked_event(): void
    {
        $tenant = $this->makeActiveTenant();
        $admin = $this->makeCompanyAdmin($tenant);

        $victim = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Revoked',
            'email' => 'rvk-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_USER,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Event::fake();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/users/{$victim->id}/status", [
            'status' => 'suspended',
        ])->assertOk();

        Event::assertDispatched(UserAccessRevoked::class, function (UserAccessRevoked $e) use ($victim, $admin): bool {
            return (int) $e->subjectUserId === (int) $victim->id
                && (int) $e->actorUserId === (int) $admin->id;
        });

        $this->assertSame(
            0,
            \App\Models\InAppNotification::query()
                ->where('notification_type', InAppNotificationTemplateKeys::USERS_ACCESS_REVOKED)
                ->count(),
            'Persist layer skips non-active recipients; inbox fan-out is asserted via event dispatch above.',
        );
    }
}
