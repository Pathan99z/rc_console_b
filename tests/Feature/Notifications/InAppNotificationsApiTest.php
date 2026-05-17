<?php

namespace Tests\Feature\Notifications;

use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Notifications\InAppNotificationTemplateKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InAppNotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    private function companyAdmin(): User
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);

        return User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Company Admin',
            'email' => 'ca-notify-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    private function plainUserWithoutInbox(User $tenantSibling): User
    {
        return User::query()->create([
            'tenant_id' => $tenantSibling->tenant_id,
            'name' => 'CRM User',
            'email' => 'plain-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => 'user',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    public function test_user_without_notifications_permission_cannot_list_inbox(): void
    {
        $admin = $this->companyAdmin();
        $plain = $this->plainUserWithoutInbox($admin);
        Sanctum::actingAs($plain);

        $this->getJson('/api/notifications')->assertForbidden();
        $this->getJson('/api/notifications/unread-count')->assertForbidden();
    }

    public function test_list_filters_and_unread_count_for_recipient_only(): void
    {
        $user = $this->companyAdmin();
        Sanctum::actingAs($user);

        InAppNotification::query()->create([
            'tenant_id' => $user->tenant_id,
            'recipient_user_id' => $user->id,
            'actor_user_id' => null,
            'notification_type' => InAppNotificationTemplateKeys::TASKS_OVERDUE,
            'category' => 'tasks',
            'title' => 'Overdue',
            'message' => 'Test',
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
            'priority' => 'high',
            'metadata' => ['k' => 1],
            'is_read' => false,
        ]);

        InAppNotification::query()->create([
            'tenant_id' => $user->tenant_id,
            'recipient_user_id' => $user->id,
            'actor_user_id' => null,
            'notification_type' => InAppNotificationTemplateKeys::TASKS_COMPLETED,
            'category' => 'tasks',
            'title' => 'Done',
            'message' => 'Test',
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
            'priority' => 'low',
            'metadata' => null,
            'is_read' => true,
            'read_at' => now(),
        ]);

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->getJson('/api/notifications?unread=1')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        $this->patchJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_cross_user_idor_blocked_for_mark_read(): void
    {
        $alice = $this->companyAdmin();
        $bob = User::query()->create([
            'tenant_id' => $alice->tenant_id,
            'name' => 'Bob',
            'email' => 'bob-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $row = InAppNotification::query()->create([
            'tenant_id' => $bob->tenant_id,
            'recipient_user_id' => $bob->id,
            'actor_user_id' => null,
            'notification_type' => InAppNotificationTemplateKeys::QUOTES_SENT,
            'category' => 'quotes',
            'title' => 'Sent',
            'message' => 'Test',
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
            'priority' => 'normal',
            'metadata' => null,
            'is_read' => false,
        ]);

        Sanctum::actingAs($alice);

        $this->patchJson("/api/notifications/{$row->id}/read")->assertStatus(404);
    }

    public function test_tenant_isolation_on_list_queries(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'A', 'status' => Tenant::STATUS_ACTIVE]);
        $tenantB = Tenant::query()->create(['name' => 'B', 'status' => Tenant::STATUS_ACTIVE]);

        $userA = User::query()->create([
            'tenant_id' => $tenantA->id,
            'name' => 'Ua',
            'email' => 'ua-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $userB = User::query()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Ub',
            'email' => 'ub-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        InAppNotification::query()->create([
            'tenant_id' => $tenantB->id,
            'recipient_user_id' => $userB->id,
            'actor_user_id' => null,
            'notification_type' => InAppNotificationTemplateKeys::TASKS_OVERDUE,
            'category' => 'tasks',
            'title' => 'Foreign',
            'message' => 'X',
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
            'priority' => 'normal',
            'metadata' => null,
            'is_read' => false,
        ]);

        Sanctum::actingAs($userA);

        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('data.pagination.total', 0);
    }

    public function test_task_digest_command_is_idempotent_through_db_layer(): void
    {
        $tenant = Tenant::query()->create(['name' => 'T', 'status' => Tenant::STATUS_ACTIVE]);
        $assignee = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Assignee',
            'email' => 'asg-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Task::query()->create([
            'tenant_id' => $tenant->id,
            'scope_organization_id' => null,
            'title' => 'Due reminder',
            'description' => null,
            'priority' => Task::PRIORITY_MEDIUM,
            'status' => Task::STATUS_PENDING,
            'due_at' => now()->startOfDay()->addHour(),
            'assignee_user_id' => $assignee->id,
            'created_by_user_id' => $assignee->id,
            'updated_by_user_id' => $assignee->id,
            'related_type' => null,
            'related_id' => null,
            'metadata' => null,
        ]);

        Artisan::call('notifications:tasks-due-reminders');
        Artisan::call('notifications:tasks-due-reminders');

        $this->assertSame(
            1,
            InAppNotification::query()
                ->where('recipient_user_id', $assignee->id)
                ->where('notification_type', InAppNotificationTemplateKeys::TASKS_DUE_TODAY)
                ->count()
        );
    }

    /** PHPUnit uses QUEUE_CONNECTION=sync; listener runs in-process so inbox row exists immediately after POST /tasks. */
    public function test_creating_assigned_task_persists_in_app_notification_for_assignee_under_sync_driver(): void
    {
        $creator = $this->companyAdmin();
        $assignee = User::query()->create([
            'tenant_id' => $creator->tenant_id,
            'name' => 'Assignee',
            'email' => 'asg-sync-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($creator);

        $this->postJson('/api/tasks', [
            'title' => 'Needs inbox row',
            'assignee_user_id' => $assignee->id,
            'priority' => Task::PRIORITY_MEDIUM,
        ])->assertCreated();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $creator->tenant_id,
            'recipient_user_id' => $assignee->id,
            'notification_type' => InAppNotificationTemplateKeys::TASKS_ASSIGNED,
        ]);

        Sanctum::actingAs($assignee);
        $this->getJson('/api/notifications?unread=1')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
    }

    /**
     * Mirrors local/prod QUEUE_CONNECTION=database: notification row appears only after a worker drains the jobs table.
     */
    public function test_database_queue_needs_worker_before_in_app_row_exists(): void
    {
        config(['queue.default' => 'database']);

        $creator = $this->companyAdmin();
        $assignee = User::query()->create([
            'tenant_id' => $creator->tenant_id,
            'name' => 'Assignee',
            'email' => 'asg-db-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($creator);

        $this->postJson('/api/tasks', [
            'title' => 'Queued listener',
            'assignee_user_id' => $assignee->id,
            'priority' => Task::PRIORITY_MEDIUM,
        ])->assertCreated();

        $this->assertSame(
            0,
            InAppNotification::query()
                ->where('recipient_user_id', $assignee->id)
                ->where('notification_type', InAppNotificationTemplateKeys::TASKS_ASSIGNED)
                ->count()
        );

        $this->assertGreaterThanOrEqual(1, DB::table('jobs')->count());

        for ($i = 0; $i < 10; $i++) {
            if (DB::table('jobs')->count() === 0) {
                break;
            }
            Artisan::call('queue:work', ['--once' => true]);
        }

        $this->assertSame(0, DB::table('jobs')->count());

        $this->assertDatabaseHas('in_app_notifications', [
            'recipient_user_id' => $assignee->id,
            'notification_type' => InAppNotificationTemplateKeys::TASKS_ASSIGNED,
        ]);
    }
}
