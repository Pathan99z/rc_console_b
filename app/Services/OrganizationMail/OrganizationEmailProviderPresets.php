<?php

namespace App\Services\OrganizationMail;

/**
 * Backend source of truth for mail provider presets (UI auto-fill).
 *
 * @phpstan-type PresetDefinition array{
 *     driver: string,
 *     host: string|null,
 *     port: int|null,
 *     encryption: string|null,
 *     manual_only: bool
 * }
 */
final class OrganizationEmailProviderPresets
{
    /**
     * Full preset row per provider code.
     *
     * @return array<string, PresetDefinition>
     */
    public static function presets(): array
    {
        return [
            'gmail' => [
                'driver' => 'smtp',
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'encryption' => 'tls',
                'manual_only' => false,
            ],
            'outlook' => [
                'driver' => 'smtp',
                'host' => 'smtp.office365.com',
                'port' => 587,
                'encryption' => 'tls',
                'manual_only' => false,
            ],
            'yahoo' => [
                'driver' => 'smtp',
                'host' => 'smtp.mail.yahoo.com',
                'port' => 465,
                'encryption' => 'ssl',
                'manual_only' => false,
            ],
            'sendgrid' => [
                'driver' => 'smtp',
                'host' => 'smtp.sendgrid.net',
                'port' => 587,
                'encryption' => 'tls',
                'manual_only' => false,
            ],
            'amazon_ses' => [
                'driver' => 'smtp',
                'host' => null,
                'port' => 587,
                'encryption' => 'tls',
                'manual_only' => false,
            ],
            'mailgun' => [
                'driver' => 'smtp',
                'host' => 'smtp.mailgun.org',
                'port' => 587,
                'encryption' => 'tls',
                'manual_only' => false,
            ],
            'smtp_com' => [
                'driver' => 'smtp',
                'host' => 'smtp.smtp.com',
                'port' => 587,
                'encryption' => 'tls',
                'manual_only' => false,
            ],
            'zoho' => [
                'driver' => 'smtp',
                'host' => 'smtp.zoho.com',
                'port' => 587,
                'encryption' => 'tls',
                'manual_only' => false,
            ],
            'mandrill' => [
                'driver' => 'smtp',
                'host' => 'smtp.mandrillapp.com',
                'port' => 587,
                'encryption' => 'tls',
                'manual_only' => false,
            ],
            'mailtrap' => [
                'driver' => 'smtp',
                'host' => 'sandbox.smtp.mailtrap.io',
                'port' => 2525,
                'encryption' => null,
                'manual_only' => false,
            ],
            'sparkpost' => [
                'driver' => 'smtp',
                'host' => 'smtp.sparkpostmail.com',
                'port' => 587,
                'encryption' => 'tls',
                'manual_only' => false,
            ],
            'smtp' => [
                'driver' => 'smtp',
                'host' => null,
                'port' => 587,
                'encryption' => 'tls',
                'manual_only' => false,
            ],
            'custom' => [
                'driver' => 'smtp',
                'host' => null,
                'port' => null,
                'encryption' => null,
                'manual_only' => true,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function providerCodes(): array
    {
        return array_keys(self::presets());
    }

    /**
     * @return PresetDefinition|null
     */
    public static function forProvider(string $provider): ?array
    {
        return self::presets()[$provider] ?? null;
    }

    /**
     * @return list<array{
     *     code: string,
     *     label: string,
     *     defaults: array{driver: string, host: string|null, port: int|null, encryption: string|null},
     *     manual_only: bool
     * }>
     */
    public static function providersForApi(): array
    {
        $labels = [
            'custom' => 'Custom',
            'smtp' => 'SMTP',
            'gmail' => 'Gmail',
            'outlook' => 'Outlook / Microsoft 365',
            'yahoo' => 'Yahoo',
            'sendgrid' => 'SendGrid',
            'amazon_ses' => 'Amazon SES',
            'mailgun' => 'Mailgun',
            'smtp_com' => 'SMTP.com',
            'zoho' => 'Zoho',
            'mandrill' => 'Mandrill',
            'mailtrap' => 'Mailtrap',
            'sparkpost' => 'SparkPost',
        ];

        $out = [];
        foreach (self::presets() as $code => $preset) {
            $out[] = [
                'code' => $code,
                'label' => $labels[$code] ?? ucfirst(str_replace('_', ' ', $code)),
                'defaults' => [
                    'driver' => $preset['driver'],
                    'host' => $preset['host'],
                    'port' => $preset['port'],
                    'encryption' => $preset['encryption'],
                ],
                'manual_only' => $preset['manual_only'],
            ];
        }

        return $out;
    }
}
