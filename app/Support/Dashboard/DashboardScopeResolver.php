<?php

namespace App\Support\Dashboard;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Repositories\OrganizationRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

readonly class DashboardScopeResolver
{
    public function __construct(private OrganizationRepository $organizationRepository) {}

    public function resolveForActor(User $actor): DashboardScope
    {
        $orgId = (int) ($actor->primaryOrganizationId() ?? 0);
        if ($orgId <= 0) {
            throw new AuthorizationException('No organization context for dashboard.');
        }

        return $this->resolve($actor, $orgId);
    }

    public function resolve(User $actor, int $organizationId): DashboardScope
    {
        $organization = $actor->isGlobalAdmin()
            ? $this->organizationRepository->findById($organizationId)
            : $this->organizationRepository->findByIdInTenant($organizationId, (int) $actor->tenant_id);

        if (! $organization) {
            throw new ModelNotFoundException('Organization not found.');
        }

        if (! in_array($organization->type, [Organization::TYPE_PARTNER, Organization::TYPE_RESELLER], true)) {
            throw new AuthorizationException('Dashboard is only available for partner and reseller organizations.');
        }

        $this->assertCanView($actor, $organization);

        $orgIds = $this->scopeOrganizationIds($organization);

        return new DashboardScope(
            tenantId: (int) $organization->tenant_id,
            rootOrganizationId: (int) $organization->id,
            organization: $organization,
            organizationIds: $orgIds,
            includesChildren: $organization->type === Organization::TYPE_PARTNER,
        );
    }

    public function assertCanView(User $actor, Organization $organization): void
    {
        if ($actor->isGlobalAdmin()) {
            return;
        }

        if ((int) $organization->tenant_id !== (int) $actor->tenant_id) {
            throw new AuthorizationException('Cross-tenant dashboard access denied.');
        }

        if ($actor->isCompanyAdmin()) {
            return;
        }

        if ($actor->currentRoleCode() === Role::CODE_PARTNER_ADMIN) {
            $tree = $this->organizationRepository->channelTreeOrganizationIds((int) ($actor->primaryOrganizationId() ?? 0));
            if (! in_array((int) $organization->id, $tree, true)) {
                throw new AuthorizationException('Partner cannot view this organization dashboard.');
            }

            return;
        }

        if ($actor->isResellerRole() && $actor->currentRoleCode() === Role::CODE_RESELLER_ADMIN) {
            if ((int) $organization->id !== (int) ($actor->primaryOrganizationId() ?? 0)) {
                throw new AuthorizationException('Reseller cannot view this organization dashboard.');
            }

            return;
        }

        throw new AuthorizationException('Not allowed to view organization dashboard.');
    }

    /**
     * @return list<int>
     */
    private function scopeOrganizationIds(Organization $organization): array
    {
        if ($organization->type === Organization::TYPE_PARTNER) {
            return $this->organizationRepository->channelTreeOrganizationIds((int) $organization->id);
        }

        return [(int) $organization->id];
    }

}
