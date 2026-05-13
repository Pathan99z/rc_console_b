<?php

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Repositories\OrganizationRepository;

class OrganizationImplicitParentApplier
{
    public function __construct(private readonly OrganizationRepository $organizationRepository) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function apply(User $actor, array &$payload): void
    {
        $tenantId = (int) $payload['tenant_id'];
        $type = (string) $payload['type'];

        $parentRaw = $payload['parent_organization_id'] ?? null;
        $parentId = $parentRaw === null || $parentRaw === '' ? null : (int) $parentRaw;

        $resolvedParent = match (true) {
            $type === Organization::TYPE_COMPANY => null,
            $parentId !== null => $parentId,
            $type === Organization::TYPE_PARTNER && ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) => $this->resolveDefaultCompanyParentId($actor, $tenantId),
            $type === Organization::TYPE_RESELLER && $actor->currentRoleCode() === Role::CODE_PARTNER_ADMIN => (int) ($actor->primaryOrganizationId() ?? 0),
            default => null,
        };

        $payload['parent_organization_id'] = $resolvedParent;
    }

    private function resolveDefaultCompanyParentId(User $actor, int $tenantId): int
    {
        $linkedId = $actor->primaryOrganizationId();
        if ($linkedId) {
            $linked = $this->organizationRepository->findByIdInTenant((int) $linkedId, $tenantId);
            if ($linked && $linked->type === Organization::TYPE_COMPANY) {
                return (int) $linked->id;
            }
        }

        return $this->organizationRepository->ensureTenantRootCompanyOrganization($tenantId, $actor->id);
    }
}
