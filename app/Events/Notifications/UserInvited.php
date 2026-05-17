<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class UserInvited
{
    use Dispatchable;

    public function __construct(public readonly int $createdUserId, public readonly int $actorUserId) {}
}
