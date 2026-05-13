<?php

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Repositories\OrganizationRepository;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class OrganizationParentOptionsResolver
{
    public function __construct(private readonly OrganizationRepository $organizationRepository) {}

    /**
     * @return list<array{id: int, tenant_id: int, type: string, display_name: string, legal_name: string|null, status: string}>
     */
    public function resolve(User $actor, array $filters): array
    {
        $childType = (string) $filters['child_type'];
        $includeInactive = filter_var($filters['include_inactive'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $tenantId = $this->resolveTenantId($actor, isset($filters['tenant_id']) ? (int) $filters['tenant_id'] : null);

        return match ($childType) {
            Organization::TYPE_COMPANY => [],
            Organization::TYPE_PARTNER => $this->partnerParentOptions($actor, $tenantId, $includeInactive),
            Organization::TYPE_RESELLER => $this->resellerParentOptions($actor, $tenantId, $includeInactive),
            default => throw ValidationException::withMessages([
                'child_type' => ['Invalid child type.'],
            ]),
        };
    }

    /**
     * @return list<array{id: int, tenant_id: int, type: string, display_name: string, legal_name: string|null, status: string}>
     */
    private function partnerParentOptions(User $actor, int $tenantId, bool $includeInactive): array
    {
        if (! $actor->isGlobalAdmin() && ! $actor->isCompanyAdmin()) {
            throw ValidationException::withMessages([
                'child_type' => ['Only company or global admins can select a parent for partner organizations.'],
            ]);
        }

        return $this->mapCandidates(
            $this->organizationRepository->listParentCandidates($tenantId, Organization::TYPE_COMPANY, null, $includeInactive)
        );
    }

    /**
     * @return list<array{id: int, tenant_id: int, type: string, display_name: string, legal_name: string|null, status: string}>
     */
    private function resellerParentOptions(User $actor, int $tenantId, bool $includeInactive): array
    {
        if ($actor->isResellerRole() || $actor->currentRoleCode() === Role::CODE_USER) {
            throw ValidationException::withMessages([
                'child_type' => ['You are not allowed to list parent options for reseller organizations.'],
            ]);
        }

        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return $this->mapCandidates(
                $this->organizationRepository->listParentCandidates($tenantId, Organization::TYPE_PARTNER, null, $includeInactive)
            );
        }

        if ($actor->currentRoleCode() === Role::CODE_PARTNER_ADMIN) {
            return $this->resellerParentOptionsForPartnerAdmin($actor, $tenantId, $includeInactive);
        }

        throw ValidationException::withMessages([
            'child_type' => ['You are not allowed to list parent options for reseller organizations.'],
        ]);
    }

    /**
     * @return list<array{id: int, tenant_id: int, type: string, display_name: string, legal_name: string|null, status: string}>
     */
    private function resellerParentOptionsForPartnerAdmin(User $actor, int $tenantId, bool $includeInactive): array
    {
        $partnerId = (int) ($actor->primaryOrganizationId() ?? 0);
        if ($partnerId <= 0) {
            return [];
        }

        $partnerOrg = $this->organizationRepository->findById($partnerId);
        if (! $partnerOrg || (int) $partnerOrg->tenant_id !== $tenantId || $partnerOrg->type !== Organization::TYPE_PARTNER) {
            return [];
        }

        return $this->mapCandidates(
            $this->organizationRepository->listParentCandidates($tenantId, Organization::TYPE_PARTNER, [$partnerId], $includeInactive)
        );
    }

    private function resolveTenantId(User $actor, ?int $tenantIdFromQuery): int
    {
        if ($actor->isGlobalAdmin()) {
            if ($tenantIdFromQuery === null) {
                throw ValidationException::withMessages([
                    'tenant_id' => ['tenant_id is required for global admin operations.'],
                ]);
            }

            return $tenantIdFromQuery;
        }

        return (int) $actor->tenant_id;
    }

    /**
     * @param  Collection<int, Organization>  $candidates
     * @return list<array{id: int, tenant_id: int, type: string, display_name: string, legal_name: string|null, status: string}>
     */
    private function mapCandidates(Collection $candidates): array
    {
        return $candidates
            ->map(fn (Organization $organization): array => [
                'id' => $organization->id,
                'tenant_id' => (int) $organization->tenant_id,
                'type' => $organization->type,
                'display_name' => $organization->display_name,
                'legal_name' => $organization->legal_name,
                'status' => $organization->status,
            ])
            ->all();
    }
}
