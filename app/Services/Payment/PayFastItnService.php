<?php

namespace App\Services\Payment;

use App\Models\PaymentRecord;
use App\Models\Quote;
use App\Events\Notifications\QuotePaymentFailed;
use App\Repositories\PaymentRecordRepository;
use App\Services\Audit\BusinessAuditService;
use App\Services\Prm\CommissionAccrualService;
use App\Services\Cache\CacheInvalidationService;
use App\Services\Quote\QuoteService;
use App\Support\Audit\BusinessAuditEventKeys;
use App\Support\DomainConstants;
use App\Support\Payment\PayFastPaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayFastItnService
{
    public function __construct(
        private readonly PaymentRecordRepository $paymentRecordRepository,
        private readonly PayFastService $payFastService,
        private readonly QuoteService $quoteService,
        private readonly InvoiceService $invoiceService,
        private readonly CommissionAccrualService $commissionAccrualService,
        private readonly BusinessAuditService $businessAuditService,
        private readonly CacheInvalidationService $cacheInvalidation,
    ) {}

    public function handle(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->all();
        Log::info(DomainConstants::LOG_PAYFAST_ITN_RECEIVED, ['payload_keys' => array_keys($data)]);

        $recordId = isset($data['m_payment_id']) ? (int) $data['m_payment_id'] : 0;
        if ($recordId <= 0) {
            $this->logWebhookFailure($request, 'missing_m_payment_id', null, 0);

            return $this->rejectItnBody();
        }

        try {
            return DB::transaction(function () use ($request, $data, $recordId): Response {
                $record = PaymentRecord::query()->whereKey($recordId)->lockForUpdate()->first();
                if (! $record) {
                    $this->logWebhookFailure($request, 'record_not_found', null, $recordId);

                    return $this->rejectItnBody();
                }

                if ($record->status === PaymentRecord::STATUS_SUCCESS) {
                    return $this->ackItn();
                }

                $credentials = $this->payFastService->resolveCredentials((int) $record->tenant_id);
                if (! $this->payFastService->verifySignature($data, $credentials->passphrase)) {
                    $this->logWebhookFailure($request, 'invalid_signature', $record, $recordId);

                    return $this->rejectItnBody();
                }

                $paymentStatus = (string) ($data['payment_status'] ?? '');
                $quote = Quote::query()->whereKey($record->quote_id)->lockForUpdate()->first();
                if (! $quote || (int) $quote->tenant_id !== (int) $record->tenant_id) {
                    $this->logWebhookFailure($request, 'quote_mismatch', $record, $recordId);

                    return $this->rejectItnBody();
                }

                if (! $this->amountsMatch($record, $data)) {
                    $this->logWebhookFailure($request, 'amount_mismatch', $record, $recordId);

                    return $this->rejectItnBody();
                }

                if ($paymentStatus === PayFastPaymentStatus::COMPLETE) {
                    $updatedRecord = $this->paymentRecordRepository->update($record, [
                        'status' => PaymentRecord::STATUS_SUCCESS,
                        'transaction_id' => (string) ($data['pf_payment_id'] ?? ''),
                        'raw_payload' => $data,
                    ]);
                    $this->quoteService->applySuccessfulPayment((int) $quote->id, $request, (int) $updatedRecord->id);
                    $invoice = $this->invoiceService->createForSuccessfulPayment($quote->loadMissing('contact'), $updatedRecord);
                    Log::info(DomainConstants::LOG_INVOICE_CREATED, [
                        'tenant_id' => $quote->tenant_id,
                        'quote_id' => $quote->id,
                        'invoice_id' => $invoice->id,
                    ]);

                    $this->commissionAccrualService->processSuccessfulPayment($quote, $updatedRecord);

                    $this->logWebhookSuccess($request, $quote, $updatedRecord);

                    return $this->ackItn();
                }

                if (in_array($paymentStatus, [PayFastPaymentStatus::FAILED, 'CANCELLED', 'EXPIRED'], true)) {
                    $this->paymentRecordRepository->update($record, [
                        'status' => PaymentRecord::STATUS_FAILED,
                        'transaction_id' => (string) ($data['pf_payment_id'] ?? ''),
                        'raw_payload' => $data,
                    ]);
                    event(new QuotePaymentFailed((int) $record->quote_id, $recordId));
                    $this->cacheInvalidation->afterPaymentMutation(
                        (int) $quote->tenant_id,
                        $quote->channel_organization_id !== null ? (int) $quote->channel_organization_id : null
                    );

                    $this->logGatewayPaymentFailed($request, $quote, $record, $paymentStatus);
                }

                return $this->ackItn();
            });
        } catch (\Throwable $e) {
            report($e);

            return response('Temporary error', 500)->header('Content-Type', 'text/plain');
        }
    }

    private function logWebhookSuccess(Request $request, Quote $quote, PaymentRecord $record): void
    {
        $this->businessAuditService->record(
            BusinessAuditEventKeys::PAYMENTS_WEBHOOK_SUCCESS,
            (int) $quote->tenant_id,
            null,
            'payments',
            'webhook_success',
            'payment_record',
            (int) $record->id,
            null,
            [
                'quote_id' => (string) $quote->id,
                'payment_status' => 'complete',
            ],
            [
                'pf_payment_id' => (string) ($record->transaction_id ?? ''),
            ],
            $quote->channel_organization_id !== null ? (int) $quote->channel_organization_id : null,
            'payfast_itn',
            $request->ip(),
            $request->userAgent(),
            $request
        );
    }

    private function logGatewayPaymentFailed(Request $request, Quote $quote, PaymentRecord $record, string $paymentStatus): void
    {
        $this->businessAuditService->record(
            BusinessAuditEventKeys::PAYMENTS_WEBHOOK_FAILED,
            (int) $quote->tenant_id,
            null,
            'payments',
            'webhook_gateway_failed',
            'payment_record',
            (int) $record->id,
            null,
            [
                'quote_id' => (string) $quote->id,
                'payment_status' => $paymentStatus,
            ],
            null,
            $quote->channel_organization_id !== null ? (int) $quote->channel_organization_id : null,
            'payfast_itn',
            $request->ip(),
            $request->userAgent(),
            $request
        );
    }

    private function logWebhookFailure(Request $request, string $reasonCode, ?PaymentRecord $record, int $recordId): void
    {
        Log::warning(DomainConstants::LOG_PAYFAST_ITN_REJECTED, ['reason' => $reasonCode]);

        $tenantId = $record !== null ? (int) $record->tenant_id : null;
        $this->businessAuditService->record(
            BusinessAuditEventKeys::PAYMENTS_WEBHOOK_FAILED,
            $tenantId,
            null,
            'payments',
            'webhook_rejected',
            'payment_record',
            $recordId,
            null,
            null,
            ['reason' => $reasonCode],
            null,
            'payfast_itn',
            $request->ip(),
            $request->userAgent(),
            $request
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function amountsMatch(PaymentRecord $record, array $data): bool
    {
        $gross = isset($data['amount_gross']) ? (float) $data['amount_gross'] : null;
        if ($gross === null) {
            return false;
        }

        return abs($gross - (float) $record->amount) < 0.01;
    }

    private function ackItn(): Response
    {
        return response('ITN received', 200)->header('Content-Type', 'text/plain');
    }

    private function rejectItnBody(): Response
    {
        return response('Bad request', 400)->header('Content-Type', 'text/plain');
    }
}
