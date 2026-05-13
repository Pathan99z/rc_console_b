<?php

namespace App\Services\Payment;

use App\Models\PaymentRecord;
use App\Models\Quote;
use App\Repositories\PaymentRecordRepository;
use App\Services\Prm\CommissionAccrualService;
use App\Services\Quote\QuoteService;
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
    ) {}

    public function handle(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->all();
        Log::info(DomainConstants::LOG_PAYFAST_ITN_RECEIVED, ['payload_keys' => array_keys($data)]);

        $recordId = isset($data['m_payment_id']) ? (int) $data['m_payment_id'] : 0;
        if ($recordId <= 0) {
            return $this->rejectItn(DomainConstants::LOG_PAYFAST_ITN_REJECTED, 'missing_m_payment_id');
        }

        try {
            return DB::transaction(function () use ($request, $data, $recordId): Response {
                $record = PaymentRecord::query()->whereKey($recordId)->lockForUpdate()->first();
                if (! $record) {
                    return $this->rejectItn(DomainConstants::LOG_PAYFAST_ITN_REJECTED, 'record_not_found');
                }

                if ($record->status === PaymentRecord::STATUS_SUCCESS) {
                    return $this->ackItn();
                }

                $credentials = $this->payFastService->resolveCredentials((int) $record->tenant_id);
                if (! $this->payFastService->verifySignature($data, $credentials->passphrase)) {
                    return $this->rejectItn(DomainConstants::LOG_PAYFAST_ITN_REJECTED, 'invalid_signature');
                }

                $paymentStatus = (string) ($data['payment_status'] ?? '');
                $quote = Quote::query()->whereKey($record->quote_id)->lockForUpdate()->first();
                if (! $quote || (int) $quote->tenant_id !== (int) $record->tenant_id) {
                    return $this->rejectItn(DomainConstants::LOG_PAYFAST_ITN_REJECTED, 'quote_mismatch');
                }

                if (! $this->amountsMatch($record, $data)) {
                    return $this->rejectItn(DomainConstants::LOG_PAYFAST_ITN_REJECTED, 'amount_mismatch');
                }

                if ($paymentStatus === PayFastPaymentStatus::COMPLETE) {
                    $updatedRecord = $this->paymentRecordRepository->update($record, [
                        'status' => PaymentRecord::STATUS_SUCCESS,
                        'transaction_id' => (string) ($data['pf_payment_id'] ?? ''),
                        'raw_payload' => $data,
                    ]);
                    $this->quoteService->applySuccessfulPayment((int) $quote->id, $request);
                    $invoice = $this->invoiceService->createForSuccessfulPayment($quote->loadMissing('contact'), $updatedRecord);
                    Log::info(DomainConstants::LOG_INVOICE_CREATED, [
                        'tenant_id' => $quote->tenant_id,
                        'quote_id' => $quote->id,
                        'invoice_id' => $invoice->id,
                    ]);

                    $this->commissionAccrualService->processSuccessfulPayment($quote, $updatedRecord);

                    return $this->ackItn();
                }

                if (in_array($paymentStatus, [PayFastPaymentStatus::FAILED, 'CANCELLED', 'EXPIRED'], true)) {
                    $this->paymentRecordRepository->update($record, [
                        'status' => PaymentRecord::STATUS_FAILED,
                        'transaction_id' => (string) ($data['pf_payment_id'] ?? ''),
                        'raw_payload' => $data,
                    ]);
                }

                return $this->ackItn();
            });
        } catch (\Throwable $e) {
            report($e);

            return response('Temporary error', 500)->header('Content-Type', 'text/plain');
        }
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

    private function rejectItn(string $logKey, string $reason): Response
    {
        Log::warning($logKey, ['reason' => $reason]);

        return response('Bad request', 400)->header('Content-Type', 'text/plain');
    }
}
