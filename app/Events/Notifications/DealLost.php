<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class DealLost
{
    use Dispatchable;

    public function __construct(public readonly int $dealId, public readonly int $actorUserId) {}
}
