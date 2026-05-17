<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class ContactAssigned
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $contactId,
        public readonly int $assignedUserId,
        public readonly ?int $actorUserId,
    ) {}
}
