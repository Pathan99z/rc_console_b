<?php

namespace Tests\Unit;

use App\Support\Payment\PayFastSignature;
use PHPUnit\Framework\TestCase;

class PayFastSignatureTest extends TestCase
{
    public function test_sign_and_verify_round_trip(): void
    {
        $data = [
            'merchant_id' => '10000100',
            'm_payment_id' => '42',
            'amount_gross' => '10.50',
            'payment_status' => 'COMPLETE',
        ];
        $passphrase = 'my-pass';
        $data['signature'] = PayFastSignature::sign($data, $passphrase);

        $this->assertTrue(PayFastSignature::verify($data, $data['signature'], $passphrase));
    }

    public function test_verify_fails_when_signature_tampered(): void
    {
        $data = [
            'merchant_id' => '1',
            'amount_gross' => '1.00',
        ];
        $data['signature'] = PayFastSignature::sign($data, 'p');

        $data['amount_gross'] = '2.00';

        $this->assertFalse(PayFastSignature::verify($data, $data['signature'], 'p'));
    }
}
