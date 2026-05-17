<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class UserAccessRevoked
{
    use Dispatchable;

    public function __construct(public readonly int $subjectUserId, public readonly ?int $actorUserId) {}
}
