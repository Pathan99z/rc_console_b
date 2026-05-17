<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class QuoteSent
{
    use Dispatchable;

    public function __construct(public readonly int $quoteId, public readonly ?int $actorUserId) {}
}
