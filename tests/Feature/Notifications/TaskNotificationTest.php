<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Support\Notifications\InAppNotificationTemplateKeys;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Concerns\BuildsTenantUsersForNotifications;
use Tests\TestCase;

class TaskNotificationTest extends TestCase
{
    use BuildsTenantUsersForNotifications;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_task_assigned_persists_tasks_assigned(): void
    {
        $tenant = $this->makeActiveTenant();
        $alice = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Alice',
            'email' => 'alice-t-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $bob = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Bob',
            'email' => 'bob-t-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($alice);

        $this->postJson('/api/tasks', [
            'title' => 'Delegated',
            'assignee_user_id' => $bob->id,
            'priority' => Task::PRIORITY_MEDIUM,
        ])->assertCreated();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $bob->id,
            'notification_type' => InAppNotificationTemplateKeys::TASKS_ASSIGNED,
        ]);
    }

    public function test_task_reassigned_persists_tasks_reassigned(): void
    {
        $tenant = $this->makeActiveTenant();
        $alice = $this->makeCompanyAdmin($tenant);
        $userA = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'A',
            'email' => 'ta-a-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $userB = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'B',
            'email' => 'ta-b-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($alice);

        $taskId = (int) $this->postJson('/api/tasks', [
            'title' => 'Handoff',
            'assignee_user_id' => $userA->id,
            'priority' => Task::PRIORITY_LOW,
        ])->assertCreated()->json('data.task.id');

        $this->postJson("/api/tasks/{$taskId}/assign", ['assignee_user_id' => $userB->id])->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $userB->id,
            'notification_type' => InAppNotificationTemplateKeys::TASKS_REASSIGNED,
        ]);
    }

    public function test_task_completed_persists_tasks_completed_for_creator(): void
    {
        $tenant = $this->makeActiveTenant();

        $creator = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Creator',
            'email' => 'creator-t-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $assignee = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Doer',
            'email' => 'doer-t-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($creator);

        $taskId = (int) $this->postJson('/api/tasks', [
            'title' => 'Implement',
            'assignee_user_id' => $assignee->id,
            'priority' => Task::PRIORITY_MEDIUM,
        ])->assertCreated()->json('data.task.id');

        Sanctum::actingAs($assignee);

        $this->postJson("/api/tasks/{$taskId}/start")->assertOk();
        $this->postJson("/api/tasks/{$taskId}/complete")->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $creator->id,
            'notification_type' => InAppNotificationTemplateKeys::TASKS_COMPLETED,
        ]);
    }

    public function test_scheduler_emits_tasks_due_today_and_tasks_overdue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 13:45:23', 'UTC'));

        $tenant = $this->makeActiveTenant();
        $assigneeDue = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'DueGuy',
            'email' => 'due-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $assigneeOver = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'OverGuy',
            'email' => 'over-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Task::query()->create([
            'tenant_id' => $tenant->id,
            'scope_organization_id' => null,
            'title' => 'Due scoped',
            'description' => null,
            'priority' => Task::PRIORITY_MEDIUM,
            'status' => Task::STATUS_PENDING,
            'due_at' => Carbon::parse('2026-06-15 10:00:00', 'UTC'),
            'assignee_user_id' => $assigneeDue->id,
            'created_by_user_id' => $assigneeDue->id,
            'updated_by_user_id' => $assigneeDue->id,
            'related_type' => null,
            'related_id' => null,
            'metadata' => null,
        ]);

        Task::query()->create([
            'tenant_id' => $tenant->id,
            'scope_organization_id' => null,
            'title' => 'Over scoped',
            'description' => null,
            'priority' => Task::PRIORITY_MEDIUM,
            'status' => Task::STATUS_PENDING,
            'due_at' => Carbon::parse('2026-06-14 12:00:00', 'UTC'),
            'assignee_user_id' => $assigneeOver->id,
            'created_by_user_id' => $assigneeOver->id,
            'updated_by_user_id' => $assigneeOver->id,
            'related_type' => null,
            'related_id' => null,
            'metadata' => null,
        ]);

        Artisan::call('notifications:tasks-due-reminders');

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $assigneeDue->id,
            'notification_type' => InAppNotificationTemplateKeys::TASKS_DUE_TODAY,
        ]);

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $assigneeOver->id,
            'notification_type' => InAppNotificationTemplateKeys::TASKS_OVERDUE,
        ]);
    }
}
