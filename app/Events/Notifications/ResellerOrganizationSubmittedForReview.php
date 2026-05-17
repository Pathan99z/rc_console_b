<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class ResellerOrganizationSubmittedForReview
{
    use Dispatchable;

    public function __construct(public readonly int $organizationId) {}
}
