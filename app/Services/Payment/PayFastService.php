<?php

namespace App\Services\Payment;

use App\Models\Quote;
use App\Models\TenantPaymentSetting;
use App\Repositories\PaymentRecordRepository;
use App\Repositories\TenantPaymentSettingRepository;
use App\Support\DomainConstants;
use App\Support\Payment\PayFastCredentials;
use App\Support\Payment\PayFastSignature;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PayFastService
{
    public function __construct(
        private readonly TenantPaymentSettingRepository $tenantPaymentSettingRepository,
        private readonly PaymentRecordRepository $paymentRecordRepository,
        private readonly PaymentSecretEncrypter $encrypter,
    ) {
    }

    public function resolveCredentials(int $tenantId): PayFastCredentials
    {
        $row = $this->tenantPaymentSettingRepository->findByTenantId($tenantId);
        if ($row && $row->merchant_id && $row->merchant_key_encrypted) {
            $key = $this->encrypter->decrypt($row->merchant_key_encrypted) ?? '';

            return new PayFastCredentials(
                merchantId: (string) $row->merchant_id,
                merchantKey: $key,
                passphrase: $this->encrypter->decrypt($row->passphrase_encrypted),
                mode: (string) $row->payfast_mode,
            );
        }

        $fallbackMode = strtolower((string) (config('payfast.fallback_mode') ?? TenantPaymentSetting::MODE_SANDBOX));

        return new PayFastCredentials(
            merchantId: (string) (config('payfast.fallback_merchant_id') ?? ''),
            merchantKey: (string) (config('payfast.fallback_merchant_key') ?? ''),
            passphrase: config('payfast.fallback_passphrase') !== null && config('payfast.fallback_passphrase') !== ''
                ? (string) config('payfast.fallback_passphrase')
                : null,
            mode: $fallbackMode === TenantPaymentSetting::MODE_LIVE ? TenantPaymentSetting::MODE_LIVE : TenantPaymentSetting::MODE_SANDBOX,
        );
    }

    /**
     * @return array{action_url: string, method: string, fields: array<string, string>, payment_record_id: int}
     */
    public function generatePaymentLink(Quote $quote, ?TenantPaymentSetting $settingsRow): array
    {
        $tenantId = (int) $quote->tenant_id;
        $credentials = $this->resolveCredentials($tenantId);
        if (! $credentials->isComplete()) {
            throw ValidationException::withMessages([
                'payment' => [DomainConstants::MSG_PAYFAST_CREDENTIALS_INCOMPLETE],
            ]);
        }

        $this->assertQuotePayable($quote);

        $record = $this->paymentRecordRepository->create([
            'tenant_id' => $tenantId,
            'quote_id' => $quote->id,
            'amount' => $quote->total,
            'currency_code' => $quote->currency_code ?? 'ZAR',
            'status' => \App\Models\PaymentRecord::STATUS_PENDING,
            'transaction_id' => null,
            'raw_payload' => null,
        ]);

        $urls = $this->resolveCallbackUrls($settingsRow);
        $contact = $quote->contact;
        $email = $contact?->email ?? '';
        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => [DomainConstants::MSG_PAYFAST_CONTACT_EMAIL_REQUIRED],
            ]);
        }

        $fields = $this->buildBaseFields($quote, $credentials, $urls, $email, (string) $record->id);
        $fields['signature'] = PayFastSignature::sign($fields, $credentials->passphrase);

        Log::info(DomainConstants::LOG_PAYFAST_LINK_GENERATED, [
            'tenant_id' => $tenantId,
            'quote_id' => $quote->id,
            'payment_record_id' => $record->id,
        ]);

        return [
            'action_url' => $this->processUrlForMode($credentials->mode),
            'method' => 'POST',
            'fields' => $fields,
            'payment_record_id' => $record->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verifySignature(array $payload, ?string $passphrase): bool
    {
        $signature = (string) ($payload['signature'] ?? '');

        return $signature !== '' && PayFastSignature::verify($payload, $signature, $passphrase);
    }

    private function assertQuotePayable(Quote $quote): void
    {
        if ((int) $quote->payment_status === Quote::PAYMENT_STATUS_PAID) {
            throw ValidationException::withMessages([
                'quote' => [DomainConstants::MSG_PAYFAST_QUOTE_ALREADY_PAID],
            ]);
        }
        if (! in_array((int) $quote->status, [Quote::STATUS_SENT, Quote::STATUS_ACCEPTED], true)) {
            throw ValidationException::withMessages([
                'quote' => [DomainConstants::MSG_PAYFAST_QUOTE_NOT_PAYABLE],
            ]);
        }
        if ((float) $quote->total <= 0) {
            throw ValidationException::withMessages([
                'quote' => [DomainConstants::MSG_PAYFAST_QUOTE_AMOUNT_INVALID],
            ]);
        }
    }

    /**
     * @return array{return_url: string, cancel_url: string, notify_url: string}
     */
    private function resolveCallbackUrls(?TenantPaymentSetting $settingsRow): array
    {
        $base = rtrim((string) config('app.url'), '/');
        $returnDefault = $base.(string) config('payfast.default_return_path');
        $cancelDefault = $base.(string) config('payfast.default_cancel_path');
        $notifyDefault = (string) (config('payfast.default_notify_url') ?: $base.'/api/payments/webhook/payfast');

        return [
            'return_url' => $settingsRow?->return_url ?: $returnDefault,
            'cancel_url' => $settingsRow?->cancel_url ?: $cancelDefault,
            'notify_url' => $settingsRow?->notify_url ?: $notifyDefault,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildBaseFields(
        Quote $quote,
        PayFastCredentials $credentials,
        array $urls,
        string $email,
        string $mPaymentId
    ): array {
        $contact = $quote->contact;
        $first = $this->sanitizeNamePart($contact?->first_name ?? 'Customer');
        $last = $this->sanitizeNamePart($contact?->last_name ?? '');

        return [
            'merchant_id' => $credentials->merchantId,
            'merchant_key' => $credentials->merchantKey,
            'return_url' => $urls['return_url'],
            'cancel_url' => $urls['cancel_url'],
            'notify_url' => $urls['notify_url'],
            'name_first' => $first,
            'name_last' => $last,
            'email_address' => mb_substr(trim(strip_tags($email)), 0, 255),
            'm_payment_id' => $mPaymentId,
            'amount' => number_format((float) $quote->total, 2, '.', ''),
            'item_name' => $this->truncateItemName('Quote '.(string) $quote->quote_number),
            'item_description' => $this->truncateItemName('Payment for quote '.(string) $quote->quote_number),
        ];
    }

    private function sanitizeNamePart(string $value): string
    {
        $clean = strip_tags(trim($value));

        return $clean === '' ? 'Customer' : mb_substr($clean, 0, 100);
    }

    private function truncateItemName(string $value): string
    {
        return mb_substr(strip_tags($value), 0, 100);
    }

    private function processUrlForMode(string $mode): string
    {
        if ($mode === TenantPaymentSetting::MODE_LIVE) {
            return (string) config('payfast.live_process_url');
        }

        return (string) config('payfast.sandbox_process_url');
    }
}
