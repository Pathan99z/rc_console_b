<?php

namespace App\Services\OrganizationMail;

use App\Models\OrganizationEmailSetting;
use App\Models\User;
use App\Services\Payment\PaymentSecretEncrypter;
use App\Support\OrganizationMail\OrganizationEmailAccessScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationEmailSettingsService
{
    public function __construct(
        private readonly OrganizationEmailAccessScope $accessScope,
        private readonly OrganizationMailResolverService $mailResolver,
        private readonly PaymentSecretEncrypter $encrypter,
        private readonly OrganizationEmailAuditLogger $auditLogger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getDetail(User $actor, int $organizationId): array
    {
        $this->accessScope->assertOrganizationEmailAccessible($actor, $organizationId);

        $row = OrganizationEmailSetting::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('organization_id', $organizationId)
            ->first();

        $effective = $this->mailResolver->resolveForTenantOrganization((int) $actor->tenant_id, $organizationId);

        return [
            'organization_id' => $organizationId,
            'configured' => $row !== null,
            'settings' => $row ? $this->serializeRowPublic($row) : null,
            'effective_mail' => $effective ? [
                'source_organization_id' => $effective->sourceOrganizationId,
                'host' => $effective->host,
                'port' => $effective->port,
                'encryption' => $effective->encryption,
                'from_address' => $effective->fromAddress,
                'from_name' => $effective->fromName,
                'reply_to' => $effective->replyTo,
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsert(User $actor, array $data, ?string $ip = null, ?string $ua = null): OrganizationEmailSetting
    {
        $organizationId = (int) $data['organization_id'];
        $this->accessScope->assertOrganizationEmailAccessible($actor, $organizationId);

        $providers = OrganizationEmailProviderPresets::providerCodes();
        $provider = (string) ($data['provider'] ?? 'smtp');
        if (! in_array($provider, $providers, true)) {
            throw ValidationException::withMessages([
                'provider' => ['Invalid mail provider.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $data, $organizationId, $provider, $ip, $ua): OrganizationEmailSetting {
            $existing = OrganizationEmailSetting::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('organization_id', $organizationId)
                ->first();

            $before = $existing?->toArray();

            $preset = OrganizationEmailProviderPresets::forProvider($provider);
            if ($preset === null) {
                throw ValidationException::withMessages([
                    'provider' => ['Invalid mail provider.'],
                ]);
            }

            $conn = $this->resolveConnectionFieldsFromPayload($data, $existing, $provider, $preset);

            $payload = [
                'tenant_id' => $actor->tenant_id,
                'organization_id' => $organizationId,
                'provider' => $provider,
                'driver' => $conn['driver'],
                'host' => $conn['host'],
                'port' => $conn['port'],
                'username' => array_key_exists('username', $data) ? ($data['username'] !== null ? (string) $data['username'] : null) : ($existing?->username),
                'from_address' => array_key_exists('from_address', $data) ? ($data['from_address'] !== null ? (string) $data['from_address'] : null) : ($existing?->from_address),
                'from_name' => array_key_exists('from_name', $data) ? ($data['from_name'] !== null ? (string) $data['from_name'] : null) : ($existing?->from_name),
                'reply_to' => array_key_exists('reply_to', $data) ? ($data['reply_to'] !== null ? (string) $data['reply_to'] : null) : ($existing?->reply_to),
                'encryption' => $conn['encryption'],
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : ($existing?->is_active ?? true),
                'metadata' => array_key_exists('metadata', $data) ? $data['metadata'] : ($existing?->metadata),
                'updated_by_user_id' => $actor->id,
            ];

            if (array_key_exists('password', $data) && $data['password'] !== null && $data['password'] !== '') {
                $payload['encrypted_password'] = $this->encrypter->encrypt((string) $data['password']);
            } elseif ($existing === null) {
                $payload['encrypted_password'] = null;
            }

            $payload['created_by_user_id'] = $existing?->created_by_user_id ?? $actor->id;

            $wasActive = $existing?->is_active ?? false;
            $willActive = isset($data['is_active']) ? (bool) $data['is_active'] : ($existing?->is_active ?? true);

            $model = OrganizationEmailSetting::query()->updateOrCreate(
                [
                    'tenant_id' => $actor->tenant_id,
                    'organization_id' => $organizationId,
                ],
                $payload,
            );

            $fresh = $model->fresh();
            $after = $fresh->toArray();

            $this->auditLogger->log($actor, 'email_settings.updated', $fresh, $before, $after, $ip, $ua);

            if ($wasActive !== $willActive) {
                $this->auditLogger->log(
                    $actor,
                    $willActive ? 'email_settings.activated' : 'email_settings.deactivated',
                    $fresh,
                    ['is_active' => $wasActive],
                    ['is_active' => $willActive],
                    $ip,
                    $ua
                );
            }

            return $fresh;
        });
    }

    /**
     * Resolve driver/host/port/encryption: explicit request values win; when the provider code
     * changes to a non-custom preset, missing connection fields are filled from backend presets.
     *
     * @param  array<string, mixed>  $data
     * @param  array{
     *     driver: string,
     *     host: string|null,
     *     port: int|null,
     *     encryption: string|null,
     *     manual_only: bool
     * }  $preset
     * @return array{driver: string, host: ?string, port: ?int, encryption: ?string}
     */
    private function resolveConnectionFieldsFromPayload(array $data, ?OrganizationEmailSetting $existing, string $targetProvider, array $preset): array
    {
        $providerChanged = $existing === null || (string) $existing->provider !== $targetProvider;
        $manualOnly = (bool) ($preset['manual_only'] ?? false);
        $applyPreset = $providerChanged && ! $manualOnly;

        return [
            'driver' => $this->resolveConnectionDriver($data, $existing, $applyPreset, $preset),
            'host' => $this->resolveConnectionHost($data, $existing, $applyPreset, $preset),
            'port' => $this->resolveConnectionPort($data, $existing, $applyPreset, $preset),
            'encryption' => $this->resolveConnectionEncryption($data, $existing, $applyPreset, $preset),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{driver?: string, host?: string|null, port?: int|null, encryption?: string|null}  $preset
     */
    private function resolveConnectionDriver(array $data, ?OrganizationEmailSetting $existing, bool $applyPreset, array $preset): string
    {
        if (array_key_exists('driver', $data)) {
            $v = $data['driver'];

            return ($v === null || $v === '') ? 'smtp' : (string) $v;
        }
        if ($applyPreset) {
            return (string) ($preset['driver'] ?? 'smtp');
        }

        return (string) ($existing?->driver ?? 'smtp');
    }

    private function normalizeHostValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{host?: string|null}  $preset
     */
    private function resolveConnectionHost(array $data, ?OrganizationEmailSetting $existing, bool $applyPreset, array $preset): ?string
    {
        if (array_key_exists('host', $data)) {
            return $this->normalizeHostValue($data['host']);
        }
        if ($applyPreset) {
            $h = $preset['host'] ?? null;

            return $h === null ? null : $this->normalizeHostValue($h);
        }

        return $existing?->host;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{port?: int|null}  $preset
     */
    private function resolveConnectionPort(array $data, ?OrganizationEmailSetting $existing, bool $applyPreset, array $preset): ?int
    {
        if (array_key_exists('port', $data)) {
            $v = $data['port'];

            return $v === null ? null : (int) $v;
        }
        if ($applyPreset) {
            $p = $preset['port'] ?? null;

            return $p === null ? null : (int) $p;
        }

        return $existing?->port;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{encryption?: string|null}  $preset
     */
    private function resolveConnectionEncryption(array $data, ?OrganizationEmailSetting $existing, bool $applyPreset, array $preset): ?string
    {
        if (array_key_exists('encryption', $data)) {
            $v = $data['encryption'];
            if ($v === null) {
                return null;
            }
            $s = trim((string) $v);

            return $s === '' ? null : $s;
        }
        if ($applyPreset) {
            $e = $preset['encryption'] ?? null;

            return $e === null ? null : (string) $e;
        }

        return $existing?->encryption;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRowPublic(OrganizationEmailSetting $row): array
    {
        return [
            'id' => $row->id,
            'organization_id' => $row->organization_id,
            'provider' => $row->provider,
            'driver' => $row->driver,
            'host' => $row->host,
            'port' => $row->port,
            'username' => $row->username,
            'has_password' => $row->encrypted_password !== null && $row->encrypted_password !== '',
            'from_address' => $row->from_address,
            'from_name' => $row->from_name,
            'reply_to' => $row->reply_to,
            'encryption' => $row->encryption,
            'is_active' => $row->is_active,
            'is_verified' => $row->is_verified,
            'last_tested_at' => $row->last_tested_at?->toIso8601String(),
            'last_error' => $row->last_error,
            'failure_count' => $row->failure_count,
            'metadata' => $row->metadata,
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
