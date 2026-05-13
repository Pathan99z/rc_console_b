<?php

namespace App\Repositories;

use App\Models\Deal;
use App\Models\Quote;
use App\Models\QuoteAttachment;
use App\Models\QuoteItem;
use App\Models\User;
use App\Services\Auth\AccessScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class QuoteRepository
{
    public function __construct(private readonly AccessScopeService $accessScopeService) {}

    public function paginateFiltered(User $actor, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = Quote::query()
            ->with(['deal', 'contact', 'createdByUser'])
            ->when(! $actor->isGlobalAdmin(), fn (Builder $q) => $q->where('tenant_id', $actor->tenant_id))
            ->when($actor->isGlobalAdmin() && isset($filters['tenant_id']), fn (Builder $q) => $q->where('tenant_id', (int) $filters['tenant_id']))
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', (int) $filters['status']))
            ->when(isset($filters['deal_id']), fn (Builder $q) => $q->where('deal_id', (int) $filters['deal_id']))
            ->when(isset($filters['contact_id']), fn (Builder $q) => $q->where('contact_id', (int) $filters['contact_id']))
            ->when(isset($filters['from_date']), fn (Builder $q) => $q->whereDate('created_at', '>=', (string) $filters['from_date']))
            ->when(isset($filters['to_date']), fn (Builder $q) => $q->whereDate('created_at', '<=', (string) $filters['to_date']));

        $this->applyVisibilityScope($query, $actor);

        return $query->orderByDesc('id')->paginate($perPage);
    }

    public function findById(int $id): ?Quote
    {
        return Quote::query()
            ->with(['deal.stage', 'contact.company', 'items.product', 'attachments.uploadedByUser', 'createdByUser', 'updatedByUser'])
            ->find($id);
    }

    public function findByPublicToken(string $token): ?Quote
    {
        return Quote::query()
            ->with(['deal.stage', 'contact.company', 'items.product', 'attachments.uploadedByUser'])
            ->where('public_uuid', $token)
            ->first();
    }

    public function create(array $payload): Quote
    {
        return Quote::query()->create($payload);
    }

    public function update(Quote $quote, array $payload): Quote
    {
        $quote->update($payload);

        return $quote->refresh();
    }

    public function delete(Quote $quote): void
    {
        $quote->delete();
    }

    public function replaceItems(Quote $quote, int $tenantId, array $items): void
    {
        QuoteItem::query()->where('quote_id', $quote->id)->delete();
        QuoteItem::query()->insert(array_map(
            fn (array $item): array => array_merge($item, [
                'tenant_id' => $tenantId,
                'quote_id' => $quote->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            $items
        ));
    }

    public function createAttachment(array $payload): QuoteAttachment
    {
        return QuoteAttachment::query()->create($payload);
    }

    public function findOpenDealForContact(int $tenantId, int $contactId): ?Deal
    {
        return Deal::query()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contactId)
            ->where('status', Deal::STATUS_OPEN)
            ->latest('id')
            ->first();
    }

    private function applyVisibilityScope(Builder $query, User $actor): void
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return;
        }

        $channelOrgIds = $this->accessScopeService->visibleChannelOrgIds($actor);

        $query->where(function (Builder $inner) use ($actor, $channelOrgIds): void {
            $this->accessScopeService->applyOwnerTeamScope($inner, $actor, 'created_by_user_id');

            if ($channelOrgIds !== []) {
                $inner->orWhereHas('deal', function (Builder $dealQ) use ($channelOrgIds): void {
                    $dealQ->whereIn('partner_organization_id', $channelOrgIds);
                });
            }
        });
    }
}
