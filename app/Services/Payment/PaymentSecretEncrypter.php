<?php

namespace App\Services\Payment;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class PaymentSecretEncrypter
{
    public function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        return Crypt::encryptString($plain);
    }

    public function decrypt(?string $cipher): ?string
    {
        if ($cipher === null || $cipher === '') {
            return null;
        }

        try {
            return Crypt::decryptString($cipher);
        } catch (DecryptException) {
            return null;
        }
    }
}
