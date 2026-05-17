<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class PartnerInvitationAccepted
{
    use Dispatchable;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $acceptedUserId,
    ) {}
}
