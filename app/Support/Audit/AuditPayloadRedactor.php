<?php

declare(strict_types=1);

namespace App\Support\Audit;

final class AuditPayloadRedactor
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'remember_token',
        'api_token',
        'access_token',
        'refresh_token',
        'secret',
        'merchant_key',
        'merchant_key_encrypted',
        'passphrase',
        'passphrase_encrypted',
        'encrypted_password',
        'authorization',
        'signature',
    ];

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public static function redact(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        return self::walk($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function walk(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            $k = strtolower((string) $key);
            if (in_array($k, self::SENSITIVE_KEYS, true) || str_contains($k, 'password') || str_contains($k, 'token')) {
                $out[$key] = '[REDACTED]';

                continue;
            }
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $out[$key] = self::walk($value);

                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
