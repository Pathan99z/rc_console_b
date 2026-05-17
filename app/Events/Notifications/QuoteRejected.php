<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class QuoteRejected
{
    use Dispatchable;

    public function __construct(public readonly int $quoteId) {}
}
