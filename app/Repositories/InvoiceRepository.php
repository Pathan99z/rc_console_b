<?php

namespace App\Repositories;

use App\Models\Invoice;
use App\Models\User;
use App\Services\Auth\AccessScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class InvoiceRepository
{
    public function __construct(private readonly AccessScopeService $accessScopeService) {}

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

    public function paginateForActor(User $actor, int $perPage = 15): LengthAwarePaginator
    {
        $query = Invoice::query()
            ->with(['quote', 'paymentRecord'])
            ->where('tenant_id', $actor->tenant_id)
            ->orderByDesc('id');

        if (! $actor->isGlobalAdmin() && ! $actor->isCompanyAdmin()) {
            $channelOrgIds = $this->accessScopeService->visibleChannelOrgIds($actor);
            $query->where(function ($inner) use ($actor, $channelOrgIds): void {
                $inner->whereHas('quote', fn ($q) => $q->where('created_by_user_id', $actor->id));

                if ($channelOrgIds !== []) {
                    $inner->orWhereHas('quote', fn ($q) => $q->whereIn('channel_organization_id', $channelOrgIds));
                    $inner->orWhereHas('quote.deal', fn ($dealQ) => $dealQ->whereIn('channel_organization_id', $channelOrgIds));
                }
            });
        }

        return $query->paginate($perPage);
    }

    public function findForActor(User $actor, int $invoiceId): ?Invoice
    {
        $query = Invoice::query()
            ->with(['quote', 'paymentRecord'])
            ->where('tenant_id', $actor->tenant_id)
            ->whereKey($invoiceId);

        if (! $actor->isGlobalAdmin() && ! $actor->isCompanyAdmin()) {
            $channelOrgIds = $this->accessScopeService->visibleChannelOrgIds($actor);
            $query->where(function ($inner) use ($actor, $channelOrgIds): void {
                $inner->whereHas('quote', fn ($q) => $q->where('created_by_user_id', $actor->id));

                if ($channelOrgIds !== []) {
                    $inner->orWhereHas('quote', fn ($q) => $q->whereIn('channel_organization_id', $channelOrgIds));
                    $inner->orWhereHas('quote.deal', fn ($dealQ) => $dealQ->whereIn('channel_organization_id', $channelOrgIds));
                }
            });
        }

        return $query->first();
    }
}
