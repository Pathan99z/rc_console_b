<?php

namespace App\Repositories;

use App\Models\Company;
use App\Models\User;
use App\Services\Auth\AccessScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class CompanyRepository
{
    public function __construct(private readonly AccessScopeService $accessScopeService) {}

    public function paginateFiltered(User $actor, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = Company::query()
            ->with(['createdByUser', 'assignedUser'])
            ->when(! $actor->isGlobalAdmin(), fn ($q) => $q->where('tenant_id', $actor->tenant_id))
            ->when($actor->isGlobalAdmin() && isset($filters['tenant_id']), fn ($q) => $q->where('tenant_id', (int) $filters['tenant_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', (int) $filters['status']))
            ->when(isset($filters['search']), function ($q) use ($filters): void {
                $search = (string) $filters['search'];
                $q->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('website', 'like', "%{$search}%")
                        ->orWhere('industry', 'like', "%{$search}%")
                        ->orWhere('company_type', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('country', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id');

        $this->applyVisibilityScope($query, $actor);

        return $query->paginate($perPage);
    }

    public function findById(int $id): ?Company
    {
        return Company::query()
            ->with(['createdByUser', 'assignedUser'])
            ->find($id);
    }

    public function create(array $data): Company
    {
        return Company::query()->create($data);
    }

    public function update(Company $company, array $data): Company
    {
        $company->update($data);

        return $company->refresh();
    }

    public function queryForExport(User $actor, array $filters): Builder
    {
        $query = Company::query()
            ->with(['createdByUser', 'assignedUser'])
            ->when(! $actor->isGlobalAdmin(), fn (Builder $q) => $q->where('tenant_id', $actor->tenant_id))
            ->when($actor->isGlobalAdmin() && isset($filters['tenant_id']), fn (Builder $q) => $q->where('tenant_id', (int) $filters['tenant_id']))
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', (int) $filters['status']))
            ->when(isset($filters['search']), function (Builder $q) use ($filters): void {
                $search = (string) $filters['search'];
                $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('website', 'like', "%{$search}%")
                        ->orWhere('industry', 'like', "%{$search}%")
                        ->orWhere('company_type', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('country', 'like', "%{$search}%");
                });
            });

        $this->applyVisibilityScope($query, $actor);

        return $query;
    }

    public function emailExistsForTenant(int $tenantId, string $email, ?int $ignoreCompanyId = null): bool
    {
        return Company::query()
            ->where('tenant_id', $tenantId)
            ->where('email', strtolower($email))
            ->when($ignoreCompanyId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreCompanyId))
            ->exists();
    }

    private function applyVisibilityScope(Builder $query, User $actor): void
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return;
        }

        $channelOrgIds = $this->accessScopeService->visibleChannelOrgIds($actor);

        $query->where(function (Builder $inner) use ($actor, $channelOrgIds): void {
            $this->accessScopeService->applyOwnerTeamScope($inner, $actor, 'assigned_user_id', 'created_by_user_id');

            if ($channelOrgIds !== []) {
                $inner->orWhereIn('channel_organization_id', $channelOrgIds);
            }
        });
    }
}
