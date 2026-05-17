<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class PartnerOrganizationSubmittedForReview
{
    use Dispatchable;

    public function __construct(public readonly int $organizationId) {}
}
