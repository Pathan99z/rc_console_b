<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class UserRoleChanged
{
    use Dispatchable;

    public function __construct(public readonly int $subjectUserId, public readonly ?int $actorUserId) {}
}
