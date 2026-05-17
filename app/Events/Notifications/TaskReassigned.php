<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class TaskReassigned
{
    use Dispatchable;

    public function __construct(
        public readonly int $taskId,
        public readonly ?int $previousAssigneeUserId,
        public readonly ?int $actorUserId,
    ) {}
}
