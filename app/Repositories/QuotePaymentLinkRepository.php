<?php

namespace App\Repositories;

use App\Models\QuotePaymentLink;

class QuotePaymentLinkRepository
{
    public function create(array $payload): QuotePaymentLink
    {
        return QuotePaymentLink::query()->create($payload);
    }

    public function findByQuoteAndId(int $tenantId, int $quoteId, int $linkId): ?QuotePaymentLink
    {
        return QuotePaymentLink::query()
            ->where('tenant_id', $tenantId)
            ->where('quote_id', $quoteId)
            ->whereKey($linkId)
            ->first();
    }

    public function findByToken(string $token): ?QuotePaymentLink
    {
        return QuotePaymentLink::query()
            ->with('quote.contact')
            ->where('token', $token)
            ->first();
    }

    public function update(QuotePaymentLink $link, array $payload): QuotePaymentLink
    {
        $link->update($payload);

        return $link->refresh();
    }
}
