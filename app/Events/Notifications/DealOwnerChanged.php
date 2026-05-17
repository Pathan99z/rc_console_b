<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class DealOwnerChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $dealId,
        public readonly ?int $previousOwnerUserId,
        public readonly int $newOwnerUserId,
        public readonly int $actorUserId,
    ) {}
}
