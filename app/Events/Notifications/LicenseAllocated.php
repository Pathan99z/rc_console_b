<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class LicenseAllocated
{
    use Dispatchable;

    public function __construct(public readonly int $entitlementId, public readonly int $actorUserId) {}
}
