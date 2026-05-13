<?php

namespace App\Repositories;

use App\Models\Organization;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class OrganizationRepository
{
    public function paginateFiltered(array $tenantIds, array $organizationIds, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return Organization::query()
            ->with(['parentOrganization'])
            ->when($tenantIds !== [], fn ($q) => $q->whereIn('tenant_id', $tenantIds))
            ->when($organizationIds !== [], fn ($q) => $q->whereIn('id', $organizationIds))
            ->when(isset($filters['type']), fn ($q) => $q->where('type', $filters['type']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['onboarding_status']), fn ($q) => $q->where('onboarding_status', $filters['onboarding_status']))
            ->when(isset($filters['search']), function ($q) use ($filters): void {
                $keyword = (string) $filters['search'];
                $q->where(function ($query) use ($keyword): void {
                    $query
                        ->where('display_name', 'like', '%'.$keyword.'%')
                        ->orWhere('legal_name', 'like', '%'.$keyword.'%')
                        ->orWhere('email', 'like', '%'.$keyword.'%')
                        ->orWhere('registration_number', 'like', '%'.$keyword.'%');
                });
            })
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function create(array $payload): Organization
    {
        return Organization::query()->create($payload);
    }

    public function findById(int $id): ?Organization
    {
        return Organization::query()
            ->with(['parentOrganization', 'childOrganizations'])
            ->find($id);
    }

    public function findByIdInTenant(int $id, int $tenantId): ?Organization
    {
        return Organization::query()
            ->withoutGlobalScopes()
            ->whereKey($id)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    public function firstCompanyOrganizationIdForTenant(int $tenantId): ?int
    {
        $id = Organization::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('type', Organization::TYPE_COMPANY)
            ->orderBy('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Creates a root {@see Organization::TYPE_COMPANY} for the tenant using {@see Tenant::$name} when none exists.
     * Tenant is the commercial boundary; this keeps partner hierarchy without manual company setup.
     */
    public function ensureTenantRootCompanyOrganization(int $tenantId, ?int $actorUserId): int
    {
        $existing = $this->firstCompanyOrganizationIdForTenant($tenantId);
        if ($existing !== null) {
            return $existing;
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            throw ValidationException::withMessages([
                'tenant_id' => ['Tenant not found.'],
            ]);
        }

        $label = trim((string) $tenant->name) !== '' ? (string) $tenant->name : 'Organization';

        try {
            $organization = Organization::query()->withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'parent_organization_id' => null,
                'type' => Organization::TYPE_COMPANY,
                'legal_name' => $label,
                'display_name' => $label,
                'onboarding_status' => Organization::ONBOARDING_ACTIVE,
                'status' => Organization::STATUS_ACTIVE,
                'created_by_user_id' => $actorUserId,
                'updated_by_user_id' => $actorUserId,
                'metadata' => ['seed' => 'tenant_root'],
            ]);

            return (int) $organization->id;
        } catch (QueryException $exception) {
            $retry = $this->firstCompanyOrganizationIdForTenant($tenantId);
            if ($retry !== null) {
                return $retry;
            }

            throw $exception;
        }
    }

    /**
     * @param  array<int>|null  $limitToIds  When non-empty, restrict to these ids (same tenant + type already applied).
     * @return Collection<int, Organization>
     */
    public function listParentCandidates(int $tenantId, string $parentType, ?array $limitToIds, bool $includeInactive): Collection
    {
        return Organization::query()
            ->where('tenant_id', $tenantId)
            ->where('type', $parentType)
            ->when(! $includeInactive, fn ($q) => $q->where('status', Organization::STATUS_ACTIVE))
            ->when($limitToIds !== null && $limitToIds !== [], fn ($q) => $q->whereIn('id', $limitToIds))
            ->orderBy('display_name')
            ->get(['id', 'tenant_id', 'type', 'display_name', 'legal_name', 'status']);
    }

    public function update(Organization $organization, array $payload): Organization
    {
        $organization->update($payload);

        return $organization->refresh();
    }

    /**
     * @return array<int>
     */
    public function descendantIds(int $organizationId): array
    {
        $result = [];
        $queue = [$organizationId];

        while ($queue !== []) {
            $parentId = array_shift($queue);
            if ($parentId === null) {
                continue;
            }

            $childIds = Organization::query()
                ->where('parent_organization_id', $parentId)
                ->pluck('id')
                ->all();

            foreach ($childIds as $childId) {
                if (! in_array((int) $childId, $result, true)) {
                    $result[] = (int) $childId;
                    $queue[] = (int) $childId;
                }
            }
        }

        return $result;
    }

    /**
     * @return list<int>
     */
    public function channelTreeOrganizationIds(int $rootOrganizationId): array
    {
        if ($rootOrganizationId <= 0) {
            return [];
        }

        return array_values(array_unique(array_merge(
            [$rootOrganizationId],
            $this->descendantIds($rootOrganizationId)
        )));
    }
}
