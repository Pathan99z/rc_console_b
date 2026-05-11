<?php

namespace App\Services\Payment;

use App\Models\TenantPaymentSetting;
use App\Models\User;
use App\Repositories\TenantPaymentSettingRepository;
use App\Support\DomainConstants;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentSettingsService
{
    public function __construct(
        private readonly TenantPaymentSettingRepository $tenantPaymentSettingRepository,
        private readonly PaymentSecretEncrypter $encrypter,
    ) {
    }

    public function getMasked(User $user, ?int $tenantIdFromClient): array
    {
        $this->assertCanManagePaymentSettings($user);
        $tenantId = $this->resolveTargetTenantId($user, $tenantIdFromClient);
        $row = $this->tenantPaymentSettingRepository->findByTenantId($tenantId);

        return [
            'tenant_id' => $tenantId,
            'payfast_mode' => $row?->payfast_mode ?? TenantPaymentSetting::MODE_SANDBOX,
            'merchant_id' => $row?->merchant_id,
            'merchant_key_masked' => $this->maskSecret($this->encrypter->decrypt($row?->merchant_key_encrypted)),
            'passphrase_configured' => $row?->passphrase_encrypted !== null && $row->passphrase_encrypted !== '',
            'return_url' => $row?->return_url,
            'cancel_url' => $row?->cancel_url,
            'notify_url' => $row?->notify_url,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsert(User $user, array $payload): array
    {
        $this->assertCanManagePaymentSettings($user);
        $tenantId = $this->resolveTargetTenantId($user, isset($payload['tenant_id']) ? (int) $payload['tenant_id'] : null);

        $encryptedKey = $this->encrypter->encrypt((string) $payload['merchant_key']);
        $encryptedPass = isset($payload['passphrase']) && (string) $payload['passphrase'] !== ''
            ? $this->encrypter->encrypt((string) $payload['passphrase'])
            : null;

        $saved = $this->tenantPaymentSettingRepository->upsert($tenantId, [
            'payfast_mode' => (string) $payload['payfast_mode'],
            'merchant_id' => (string) $payload['merchant_id'],
            'merchant_key_encrypted' => $encryptedKey,
            'passphrase_encrypted' => $encryptedPass,
            'return_url' => $payload['return_url'] ?? null,
            'cancel_url' => $payload['cancel_url'] ?? null,
            'notify_url' => $payload['notify_url'] ?? null,
        ]);

        Log::info(DomainConstants::LOG_PAYMENT_SETTINGS_SAVED, ['tenant_id' => $tenantId, 'user_id' => $user->id]);

        return $this->formatMaskedFromRow($saved);
    }

    private function formatMaskedFromRow(TenantPaymentSetting $row): array
    {
        return [
            'tenant_id' => (int) $row->tenant_id,
            'payfast_mode' => $row->payfast_mode,
            'merchant_id' => $row->merchant_id,
            'merchant_key_masked' => $this->maskSecret($this->encrypter->decrypt($row->merchant_key_encrypted)),
            'passphrase_configured' => $row->passphrase_encrypted !== null && $row->passphrase_encrypted !== '',
            'return_url' => $row->return_url,
            'cancel_url' => $row->cancel_url,
            'notify_url' => $row->notify_url,
        ];
    }

    private function assertCanManagePaymentSettings(User $user): void
    {
        if (! $user->isCompanyAdmin() && ! $user->isGlobalAdmin()) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => DomainConstants::MSG_PAYMENT_SETTINGS_FORBIDDEN,
                'errors' => (object) [],
            ], 403));
        }
    }

    private function resolveTargetTenantId(User $user, ?int $tenantIdFromClient): int
    {
        if ($user->isGlobalAdmin()) {
            if ($tenantIdFromClient === null) {
                throw ValidationException::withMessages(['tenant_id' => [DomainConstants::MSG_TENANT_REQUIRED]]);
            }

            return $tenantIdFromClient;
        }

        return (int) $user->tenant_id;
    }

    private function maskSecret(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        $len = strlen($plain);
        if ($len <= 4) {
            return '****';
        }

        return substr($plain, 0, 2).str_repeat('*', max(4, $len - 4)).substr($plain, -2);
    }
}
