<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\User;
use App\Repositories\AuditLogRepository;

class TaskAuditLogger
{
    public function __construct(private readonly AuditLogRepository $auditLogRepository) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        User $actor,
        string $action,
        Task $task,
        ?array $before = null,
        ?array $after = null,
        ?string $ip = null,
        ?string $ua = null
    ): void {
        $this->auditLogRepository->create([
            'tenant_id' => $task->tenant_id,
            'user_id' => $actor->id,
            'module' => 'tasks',
            'action' => $action,
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'before' => $before,
            'after' => $after ?? $task->toArray(),
            'ip_address' => $ip,
            'user_agent' => $ua,
        ]);
    }
}
