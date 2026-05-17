<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\User;
use App\Repositories\OrganizationRepository;
use App\Support\Tasks\TaskAccessScope;
use App\Support\Tasks\TaskStatusTransition;
use App\Events\Notifications\TaskAssigned;
use App\Events\Notifications\TaskCompleted;
use App\Events\Notifications\TaskReassigned;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskManagementService
{
    public function __construct(
        private readonly TaskAccessScope $accessScope,
        private readonly TaskAssignableUserResolver $assignableUserResolver,
        private readonly TaskRelatedEntityResolver $relatedEntityResolver,
        private readonly TaskAuditLogger $auditLogger,
        private readonly OrganizationRepository $organizationRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createTask(User $actor, array $payload, ?string $ip = null, ?string $ua = null): Task
    {
        $tenantId = $this->resolveTenantId($actor);
        $this->validatePriority($payload['priority'] ?? Task::PRIORITY_MEDIUM);
        $scopeOrgId = isset($payload['scope_organization_id']) ? (int) $payload['scope_organization_id'] : null;
        $this->accessScope->assertScopeOrganizationAllowed($actor, $scopeOrgId);

        $assigneeId = isset($payload['assignee_user_id']) ? (int) $payload['assignee_user_id'] : null;
        $assignee = $this->resolveAssignee($actor, $assigneeId, $scopeOrgId);

        $this->relatedEntityResolver->validateRelated(
            $actor,
            $payload['related_type'] ?? null,
            isset($payload['related_id']) ? (int) $payload['related_id'] : null
        );

        $task = Task::query()->create([
            'tenant_id' => $tenantId,
            'scope_organization_id' => $scopeOrgId,
            'title' => (string) $payload['title'],
            'description' => $payload['description'] ?? null,
            'priority' => (string) ($payload['priority'] ?? Task::PRIORITY_MEDIUM),
            'status' => Task::STATUS_PENDING,
            'due_at' => $payload['due_at'] ?? null,
            'assignee_user_id' => $assignee?->id,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
            'related_type' => $payload['related_type'] ?? null,
            'related_id' => isset($payload['related_id']) ? (int) $payload['related_id'] : null,
            'metadata' => $payload['metadata'] ?? null,
        ]);

        $fresh = $this->loadTask($task->id);
        $this->auditLogger->log($actor, 'tasks.create', $fresh, null, $fresh->toArray(), $ip, $ua);

        if ($fresh->assignee_user_id) {
            event(new TaskAssigned($fresh->id, $actor->id));
        }

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateTask(User $actor, int $taskId, array $payload, ?string $ip = null, ?string $ua = null): Task
    {
        $task = $this->mustGetTask($taskId);
        $this->accessScope->assertCanManageTask($actor, $task);
        $before = $task->toArray();

        if (isset($payload['scope_organization_id'])) {
            $scopeOrgId = $payload['scope_organization_id'] !== null
                ? (int) $payload['scope_organization_id']
                : null;
            $this->accessScope->assertScopeOrganizationAllowed($actor, $scopeOrgId);
            $task->scope_organization_id = $scopeOrgId;
        }

        if (array_key_exists('title', $payload)) {
            $task->title = (string) $payload['title'];
        }
        if (array_key_exists('description', $payload)) {
            $task->description = $payload['description'];
        }
        if (isset($payload['priority'])) {
            $this->validatePriority((string) $payload['priority']);
            $task->priority = (string) $payload['priority'];
        }
        if (array_key_exists('due_at', $payload)) {
            $task->due_at = $payload['due_at'];
        }
        if (array_key_exists('related_type', $payload) || array_key_exists('related_id', $payload)) {
            $relatedType = $payload['related_type'] ?? $task->related_type;
            $relatedId = array_key_exists('related_id', $payload)
                ? ($payload['related_id'] !== null ? (int) $payload['related_id'] : null)
                : $task->related_id;
            $this->relatedEntityResolver->validateRelated($actor, $relatedType, $relatedId);
            $task->related_type = $relatedType;
            $task->related_id = $relatedId;
        }

        $task->updated_by_user_id = $actor->id;
        $task->save();

        $fresh = $this->loadTask($task->id);
        $this->auditLogger->log($actor, 'tasks.update', $fresh, $before, $fresh->toArray(), $ip, $ua);

        return $fresh;
    }

    public function assignTask(User $actor, int $taskId, int $assigneeUserId, ?string $ip = null, ?string $ua = null): Task
    {
        $task = $this->mustGetTask($taskId);
        $this->accessScope->assertCanManageTask($actor, $task);
        $before = $task->toArray();

        $assignee = $this->resolveAssignee($actor, $assigneeUserId, $task->scope_organization_id);
        $previousAssignee = $task->assignee_user_id !== null ? (int) $task->assignee_user_id : null;
        $task->update([
            'assignee_user_id' => $assignee->id,
            'updated_by_user_id' => $actor->id,
        ]);

        $fresh = $this->loadTask($task->id);
        $this->auditLogger->log($actor, 'tasks.assign', $fresh, $before, $fresh->toArray(), $ip, $ua);

        if ($previousAssignee !== (int) $assignee->id) {
            if ($previousAssignee === null) {
                event(new TaskAssigned($fresh->id, $actor->id));
            } else {
                event(new TaskReassigned($fresh->id, $previousAssignee, $actor->id));
            }
        }

        return $fresh;
    }

    public function startTask(User $actor, int $taskId, ?string $ip = null, ?string $ua = null): Task
    {
        return $this->transitionTask($actor, $taskId, Task::STATUS_IN_PROGRESS, 'tasks.start', $ip, $ua);
    }

    public function completeTask(User $actor, int $taskId, ?string $ip = null, ?string $ua = null): Task
    {
        return DB::transaction(function () use ($actor, $taskId, $ip, $ua): Task {
            $task = $this->mustGetTask($taskId);
            $this->accessScope->assertCanManageTask($actor, $task);
            $before = $task->toArray();
            TaskStatusTransition::assertCanTransition($task->status, Task::STATUS_COMPLETED);

            $task->update([
                'status' => Task::STATUS_COMPLETED,
                'completed_at' => now(),
                'cancelled_at' => null,
                'updated_by_user_id' => $actor->id,
            ]);

            $fresh = $this->loadTask($task->id);
            $this->auditLogger->log($actor, 'tasks.complete', $fresh, $before, $fresh->toArray(), $ip, $ua);

            event(new TaskCompleted($fresh->id, $actor->id));

            return $fresh;
        });
    }

    public function cancelTask(User $actor, int $taskId, ?string $ip = null, ?string $ua = null): Task
    {
        return DB::transaction(function () use ($actor, $taskId, $ip, $ua): Task {
            $task = $this->mustGetTask($taskId);
            $this->accessScope->assertCanManageTask($actor, $task);
            $before = $task->toArray();
            TaskStatusTransition::assertCanTransition($task->status, Task::STATUS_CANCELLED);

            $task->update([
                'status' => Task::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'updated_by_user_id' => $actor->id,
            ]);

            $fresh = $this->loadTask($task->id);
            $this->auditLogger->log($actor, 'tasks.cancel', $fresh, $before, $fresh->toArray(), $ip, $ua);

            return $fresh;
        });
    }

    public function reopenTask(User $actor, int $taskId, ?string $ip = null, ?string $ua = null): Task
    {
        return $this->transitionTask($actor, $taskId, Task::STATUS_PENDING, 'tasks.reopen', $ip, $ua, [
            'completed_at' => null,
            'cancelled_at' => null,
        ]);
    }

    public function deleteTask(User $actor, int $taskId, ?string $ip = null, ?string $ua = null): void
    {
        $task = $this->mustGetTask($taskId);
        $this->accessScope->assertCanManageTask($actor, $task);
        $before = $task->toArray();
        $task->delete();
        $this->auditLogger->log($actor, 'tasks.delete', $task, $before, null, $ip, $ua);
    }

    public function getTask(User $actor, int $taskId): Task
    {
        $task = $this->loadTask($taskId);
        $this->accessScope->assertCanViewTask($actor, $task);

        return $task;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listTasks(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $view = (string) ($filters['view'] ?? 'my');
        if (! $this->accessScope->canUseListView($actor, $view)) {
            throw ValidationException::withMessages([
                'view' => ['Invalid or unauthorized list view.'],
            ]);
        }

        $q = Task::query()
            ->with(['assignee', 'creator', 'scopeOrganization'])
            ->orderByDesc('id');

        $this->accessScope->applyListScope($q, $actor, $view);

        if (! empty($filters['status'])) {
            $q->where('status', (string) $filters['status']);
        }
        if (! empty($filters['priority'])) {
            $q->where('priority', (string) $filters['priority']);
        }
        if (! empty($filters['assignee_user_id'])) {
            $q->where('assignee_user_id', (int) $filters['assignee_user_id']);
        }
        if (! empty($filters['created_by_user_id'])) {
            $q->where('created_by_user_id', (int) $filters['created_by_user_id']);
        }
        if (! empty($filters['scope_organization_id'])) {
            $q->where('scope_organization_id', (int) $filters['scope_organization_id']);
        }
        if (! empty($filters['related_type'])) {
            $q->where('related_type', (string) $filters['related_type']);
        }
        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $q->where(function ($inner) use ($search): void {
                $inner->where('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }
        if (! empty($filters['overdue']) && filter_var($filters['overdue'], FILTER_VALIDATE_BOOLEAN)) {
            $q->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->whereNotIn('status', [Task::STATUS_COMPLETED, Task::STATUS_CANCELLED]);
        }

        return $q->paginate($perPage);
    }

    private function transitionTask(
        User $actor,
        int $taskId,
        string $toStatus,
        string $auditAction,
        ?string $ip,
        ?string $ua,
        array $extra = []
    ): Task {
        return DB::transaction(function () use ($actor, $taskId, $toStatus, $auditAction, $ip, $ua, $extra): Task {
            $task = $this->mustGetTask($taskId);
            $this->accessScope->assertCanManageTask($actor, $task);
            $before = $task->toArray();
            TaskStatusTransition::assertCanTransition($task->status, $toStatus);

            $task->update(array_merge([
                'status' => $toStatus,
                'updated_by_user_id' => $actor->id,
            ], $extra));

            $fresh = $this->loadTask($task->id);
            $this->auditLogger->log($actor, $auditAction, $fresh, $before, $fresh->toArray(), $ip, $ua);

            return $fresh;
        });
    }

    private function mustGetTask(int $taskId): Task
    {
        $task = Task::query()->find($taskId);
        if (! $task) {
            throw new ModelNotFoundException('Task not found.');
        }

        return $task;
    }

    private function loadTask(int $taskId): Task
    {
        return Task::query()
            ->with(['assignee', 'creator', 'updater', 'scopeOrganization'])
            ->findOrFail($taskId);
    }

    private function resolveTenantId(User $actor): int
    {
        if ($actor->isGlobalAdmin() && $actor->tenant_id === null) {
            throw ValidationException::withMessages([
                'tenant_id' => ['Tenant context is required.'],
            ]);
        }

        return (int) $actor->tenant_id;
    }

    private function validatePriority(string $priority): void
    {
        if (! in_array($priority, Task::priorities(), true)) {
            throw ValidationException::withMessages([
                'priority' => ['Invalid priority.'],
            ]);
        }
    }

    private function resolveAssignee(User $actor, ?int $assigneeId, ?int $scopeOrgId): ?User
    {
        if ($assigneeId === null || $assigneeId <= 0) {
            return null;
        }

        $assignee = User::query()->find($assigneeId);
        if (! $assignee) {
            throw ValidationException::withMessages([
                'assignee_user_id' => ['Assignee not found.'],
            ]);
        }

        $scopeOrg = $scopeOrgId ? $this->organizationRepository->findById($scopeOrgId) : null;
        $this->accessScope->assertCanAssignTask($actor, $assignee, $scopeOrg);

        return $assignee;
    }
}
