<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class TaskAssigned
{
    use Dispatchable;

    public function __construct(public readonly int $taskId, public readonly ?int $actorUserId) {}
}
