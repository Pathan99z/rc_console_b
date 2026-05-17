<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\Notifications\QuoteSent;
use App\Events\Notifications\TaskAssigned;
use App\Listeners\Notifications\PersistQueuedInAppNotification;
use App\Models\Role;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

final class QueueSafetyNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_assigned_queues_persist_listener_with_after_commit(): void
    {
        Queue::fake();
        NotificationFacade::fake();

        $tenant = Tenant::factory()->create();
        $actor = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Actor Queue',
            'email' => 'aq-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $task = Task::query()->create([
            'tenant_id' => $tenant->id,
            'scope_organization_id' => null,
            'title' => 'Queued safety',
            'priority' => Task::PRIORITY_MEDIUM,
            'status' => Task::STATUS_PENDING,
            'assignee_user_id' => $actor->id,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
            'related_type' => null,
            'related_id' => null,
        ]);

        event(new TaskAssigned($task->id, $actor->id));

        Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) use ($task): bool {
            if ($job->class !== PersistQueuedInAppNotification::class) {
                return false;
            }

            Assert::assertTrue((bool) $job->afterCommit);

            return $job->data[0] instanceof TaskAssigned
                && (int) $job->data[0]->taskId === (int) $task->id;
        });
    }

    public function test_quote_sent_event_queues_persistent_listener_stub(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $actor = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Quote Actor',
            'email' => 'qa-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        event(new QuoteSent(9_876_543, $actor->id));

        Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job): bool {
            if ($job->class !== PersistQueuedInAppNotification::class) {
                return false;
            }

            Assert::assertTrue((bool) $job->afterCommit);

            return $job->data[0] instanceof QuoteSent;
        });
    }

    /**
     * Confirms Laravel's notification channel fake stacks alongside queued listeners.
     */
    public function test_notification_fake_stacks_without_interfering_with_queue_assertions(): void
    {
        NotificationFacade::fake();

        Queue::fake();

        $tenant = Tenant::factory()->create();
        $actor = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Facade',
            'email' => 'fac-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        event(new QuoteSent(4242, $actor->id));
        Queue::assertPushed(CallQueuedListener::class);
    }
}
