<?php

namespace App\Support\Payment;

final class PayFastSignature
{
    /**
     * Build PayFast signature string (alphabetical keys, non-empty values, optional passphrase).
     *
     * @param  array<string, mixed>  $data
     */
    public static function dataString(array $data, ?string $passphrase = null): string
    {
        $copy = $data;
        unset($copy['signature']);
        ksort($copy);
        $parts = [];
        foreach ($copy as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $stringValue = stripslashes(trim((string) $value));
            $parts[] = $key.'='.urlencode($stringValue);
        }
        $output = implode('&', $parts);
        if ($passphrase !== null && $passphrase !== '') {
            $output .= '&passphrase='.urlencode($passphrase);
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function sign(array $data, ?string $passphrase = null): string
    {
        return md5(self::dataString($data, $passphrase));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function verify(array $data, string $expectedSignature, ?string $passphrase = null): bool
    {
        $computed = self::sign($data, $passphrase);

        return hash_equals(strtolower($computed), strtolower($expectedSignature));
    }
}
