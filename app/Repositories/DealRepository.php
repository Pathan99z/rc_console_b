<?php

namespace App\Repositories;

use App\Models\Deal;
use App\Models\User;
use App\Services\Auth\AccessScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class DealRepository
{
    public function __construct(private readonly AccessScopeService $accessScopeService) {}

    public function paginateFiltered(User $actor, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = Deal::query()
            ->with(['contact', 'company', 'owner', 'pipeline', 'stage'])
            ->when(! $actor->isGlobalAdmin(), fn (Builder $q) => $q->where('tenant_id', $actor->tenant_id))
            ->when($actor->isGlobalAdmin() && isset($filters['tenant_id']), fn (Builder $q) => $q->where('tenant_id', (int) $filters['tenant_id']))
            ->when(isset($filters['pipeline_id']), fn (Builder $q) => $q->where('pipeline_id', (int) $filters['pipeline_id']))
            ->when(isset($filters['stage_id']), fn (Builder $q) => $q->where('pipeline_stage_id', (int) $filters['stage_id']))
            ->when(isset($filters['contact_id']), fn (Builder $q) => $q->where('contact_id', (int) $filters['contact_id']))
            ->when(isset($filters['owner_id']), fn (Builder $q) => $q->where('owner_user_id', (int) $filters['owner_id']))
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', (int) $filters['status']))
            ->when(isset($filters['search']), function (Builder $q) use ($filters): void {
                $search = (string) $filters['search'];
                $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhereHas('contact', fn (Builder $contactQ) => $contactQ
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"));
                });
            });

        $this->applyVisibilityScope($query, $actor);

        return $query->orderByDesc('id')->paginate($perPage);
    }

    public function findById(int $id): ?Deal
    {
        return Deal::query()
            ->with(['contact', 'company', 'owner', 'pipeline', 'stage', 'histories.user'])
            ->find($id);
    }

    public function create(array $data): Deal
    {
        return Deal::query()->create($data);
    }

    public function update(Deal $deal, array $data): Deal
    {
        $deal->update($data);

        return $deal->refresh();
    }

    public function delete(Deal $deal): void
    {
        $deal->delete();
    }

    private function applyVisibilityScope(Builder $query, User $actor): void
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return;
        }

        $query->where(function (Builder $inner) use ($actor): void {
            $this->accessScopeService->applyOwnerTeamScope($inner, $actor, 'owner_user_id');
            $inner->orWhere(function (Builder $channelQ) use ($actor): void {
                $this->accessScopeService->applyChannelOrganizationScope(
                    $channelQ,
                    $actor,
                    allowLegacyPartnerColumn: false,
                );
            });
        });
    }
}
