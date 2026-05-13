<?php

namespace App\Services\Payment;

use App\Models\Invoice;
use App\Models\PaymentRecord;
use App\Models\Quote;
use App\Models\User;
use App\Repositories\InvoiceRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class InvoiceService
{
    public function __construct(private readonly InvoiceRepository $invoiceRepository) {}

    public function createForSuccessfulPayment(Quote $quote, PaymentRecord $paymentRecord): Invoice
    {
        $existing = $this->invoiceRepository->findByTenantAndQuote((int) $quote->tenant_id, (int) $quote->id);
        if ($existing) {
            return $existing;
        }

        $contact = $quote->contact;
        $customerName = trim((string) (($contact?->first_name ?? '').' '.($contact?->last_name ?? '')));
        if ($customerName === '') {
            $customerName = 'Customer';
        }

        return $this->invoiceRepository->create([
            'tenant_id' => $quote->tenant_id,
            'quote_id' => $quote->id,
            'payment_record_id' => $paymentRecord->id,
            'invoice_number' => $this->generateInvoiceNumber((int) $quote->tenant_id),
            'status' => Invoice::STATUS_PAID,
            'customer_name' => $customerName,
            'customer_email' => $contact?->email,
            'subtotal' => $quote->subtotal,
            'tax_total' => $quote->tax_total,
            'discount_total' => $quote->discount_total,
            'total' => $quote->total,
            'currency_code' => $quote->currency_code,
            'issued_at' => now(),
            'paid_at' => now(),
            'meta' => [
                'source' => 'payfast_itn',
                'quote_number' => $quote->quote_number,
            ],
        ]);
    }

    public function listInvoices(User $actor, int $perPage = 15): LengthAwarePaginator
    {
        return $this->invoiceRepository->paginateForActor($actor, $perPage);
    }

    public function getInvoice(User $actor, int $invoiceId): Invoice
    {
        $invoice = $this->invoiceRepository->findForActor($actor, $invoiceId);
        if (! $invoice) {
            throw new ModelNotFoundException('Invoice not found.');
        }

        return $invoice;
    }

    private function generateInvoiceNumber(int $tenantId): string
    {
        $count = Invoice::query()->where('tenant_id', $tenantId)->count() + 1;

        return sprintf('INV-%d-%06d', $tenantId, $count);
    }
}
