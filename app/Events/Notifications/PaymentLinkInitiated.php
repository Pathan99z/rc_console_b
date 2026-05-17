<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class PaymentLinkInitiated
{
    use Dispatchable;

    public function __construct(
        public readonly int $quoteId,
        public readonly int $paymentRecordId,
        public readonly ?int $initiatedByUserId,
    ) {}
}
