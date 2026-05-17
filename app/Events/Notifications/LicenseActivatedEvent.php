<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class LicenseActivatedEvent
{
    use Dispatchable;

    public function __construct(
        public readonly int $entitlementId,
        public readonly int $activatedByUserId,
        public readonly int $units,
        public readonly ?int $activationId,
    ) {}
}
