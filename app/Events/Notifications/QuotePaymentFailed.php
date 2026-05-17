<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class QuotePaymentFailed
{
    use Dispatchable;

    public function __construct(public readonly int $quoteId, public readonly ?int $paymentRecordId) {}
}
