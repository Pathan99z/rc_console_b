<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Task;
use App\Models\User;
use App\Services\Auth\PermissionResolverService;
use App\Services\Tasks\TaskAssignableUserResolver;
use App\Services\Tasks\TaskManagementService;
use App\Services\Tasks\TaskRelatedEntityResolver;
use App\Support\DomainConstants;
use App\Support\Tasks\TaskAccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TaskManagementService $taskService,
        private readonly TaskAssignableUserResolver $assignableUserResolver,
        private readonly TaskRelatedEntityResolver $relatedEntityResolver,
        private readonly TaskAccessScope $accessScope,
        private readonly PermissionResolverService $permissionResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search',
            'status',
            'priority',
            'assignee_user_id',
            'created_by_user_id',
            'scope_organization_id',
            'related_type',
            'view',
            'overdue',
        ]);
        $items = $this->taskService->listTasks(
            $request->user(),
            $filters,
            (int) $request->input('per_page', 15)
        );

        return $this->successResponse(DomainConstants::MSG_TASK_FETCHED, [
            'items' => collect($items->items())->map(fn (Task $t) => $this->taskItem($request->user(), $t)),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', 'string', 'in:'.implode(',', Task::priorities())],
            'due_at' => ['nullable', 'date'],
            'assignee_user_id' => ['nullable', 'integer'],
            'scope_organization_id' => ['nullable', 'integer'],
            'related_type' => ['nullable', 'string', 'in:'.implode(',', Task::relatedTypes())],
            'related_id' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
        ]);

        $task = $this->taskService->createTask(
            $request->user(),
            $data,
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_TASK_CREATED, [
            'task' => $this->taskItem($request->user(), $task),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $task = $this->taskService->getTask($request->user(), $id);

        return $this->successResponse(DomainConstants::MSG_TASK_FETCHED, [
            'task' => $this->taskItem($request->user(), $task),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['sometimes', 'string', 'in:'.implode(',', Task::priorities())],
            'due_at' => ['nullable', 'date'],
            'scope_organization_id' => ['nullable', 'integer'],
            'related_type' => ['nullable', 'string', 'in:'.implode(',', Task::relatedTypes())],
            'related_id' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
        ]);

        $task = $this->taskService->updateTask(
            $request->user(),
            $id,
            $data,
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_TASK_UPDATED, [
            'task' => $this->taskItem($request->user(), $task),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->taskService->deleteTask($request->user(), $id, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_TASK_DELETED);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'assignee_user_id' => ['required', 'integer'],
        ]);

        $task = $this->taskService->assignTask(
            $request->user(),
            $id,
            (int) $data['assignee_user_id'],
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_TASK_ASSIGNED, [
            'task' => $this->taskItem($request->user(), $task),
        ]);
    }

    public function start(Request $request, int $id): JsonResponse
    {
        $task = $this->taskService->startTask($request->user(), $id, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_TASK_UPDATED, [
            'task' => $this->taskItem($request->user(), $task),
        ]);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $task = $this->taskService->completeTask($request->user(), $id, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_TASK_UPDATED, [
            'task' => $this->taskItem($request->user(), $task),
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $task = $this->taskService->cancelTask($request->user(), $id, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_TASK_UPDATED, [
            'task' => $this->taskItem($request->user(), $task),
        ]);
    }

    public function reopen(Request $request, int $id): JsonResponse
    {
        $task = $this->taskService->reopenTask($request->user(), $id, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_TASK_UPDATED, [
            'task' => $this->taskItem($request->user(), $task),
        ]);
    }

    public function assignableUsers(Request $request): JsonResponse
    {
        $search = $request->input('search');
        $users = $this->assignableUserResolver->getAssignableUsers($request->user(), is_string($search) ? $search : null);

        return $this->successResponse(DomainConstants::MSG_TASK_ASSIGNABLE_USERS_FETCHED, [
            'users' => $this->assignableUserResolver->formatAssignableUsers($users),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function taskItem(User $actor, Task $task): array
    {
        $canManage = $this->canManage($actor, $task);
        $canAssign = $this->permissionResolver->can($actor, 'tasks.assign') && $canManage;

        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'priority' => $task->priority,
            'status' => $task->status,
            'is_overdue' => $task->is_overdue,
            'due_at' => $task->due_at?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'cancelled_at' => $task->cancelled_at?->toIso8601String(),
            'assignee_user_id' => $task->assignee_user_id,
            'created_by_user_id' => $task->created_by_user_id,
            'scope_organization_id' => $task->scope_organization_id,
            'related_type' => $task->related_type,
            'related_id' => $task->related_id,
            'assignee' => $task->assignee ? [
                'id' => $task->assignee->id,
                'name' => $task->assignee->name,
                'email' => $task->assignee->email,
            ] : null,
            'creator' => $task->creator ? [
                'id' => $task->creator->id,
                'name' => $task->creator->name,
                'email' => $task->creator->email,
            ] : null,
            'scope_organization' => $task->scopeOrganization ? [
                'id' => $task->scopeOrganization->id,
                'type' => $task->scopeOrganization->type,
                'display_name' => $task->scopeOrganization->display_name,
                'legal_name' => $task->scopeOrganization->legal_name,
            ] : null,
            'related' => $this->relatedEntityResolver->summarize($task),
            'permissions' => [
                'can_view' => true,
                'can_edit' => $canManage,
                'can_assign' => $canAssign,
                'can_complete' => $canManage && $task->status !== Task::STATUS_COMPLETED,
                'can_cancel' => $canManage && ! in_array($task->status, [Task::STATUS_COMPLETED, Task::STATUS_CANCELLED], true),
                'can_reopen' => $canManage && in_array($task->status, [Task::STATUS_COMPLETED, Task::STATUS_CANCELLED], true),
            ],
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];
    }

    private function canManage(User $actor, Task $task): bool
    {
        try {
            $this->accessScope->assertCanManageTask($actor, $task);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
