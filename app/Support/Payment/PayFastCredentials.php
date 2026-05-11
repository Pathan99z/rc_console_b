<?php

namespace App\Support\Payment;

final readonly class PayFastCredentials
{
    public function __construct(
        public string $merchantId,
        public string $merchantKey,
        public ?string $passphrase,
        public string $mode,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->merchantId !== '' && $this->merchantKey !== '';
    }
}
