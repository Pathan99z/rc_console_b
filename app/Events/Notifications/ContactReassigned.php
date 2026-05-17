<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class ContactReassigned
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $contactId,
        public readonly int $newAssigneeUserId,
        public readonly ?int $actorUserId,
    ) {}
}
