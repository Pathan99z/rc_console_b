<?php

namespace App\Repositories;

use App\Models\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class InvoiceRepository
{
    public function findByTenantAndQuote(int $tenantId, int $quoteId): ?Invoice
    {
        return Invoice::query()
            ->where('tenant_id', $tenantId)
            ->where('quote_id', $quoteId)
            ->first();
    }

    public function create(array $payload): Invoice
    {
        return Invoice::query()->create($payload);
    }

    public function paginateForTenant(int $tenantId, int $perPage = 15): LengthAwarePaginator
    {
        return Invoice::query()
            ->with(['quote', 'paymentRecord'])
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findForTenant(int $tenantId, int $invoiceId): ?Invoice
    {
        return Invoice::query()
            ->with(['quote', 'paymentRecord'])
            ->where('tenant_id', $tenantId)
            ->find($invoiceId);
    }
}
