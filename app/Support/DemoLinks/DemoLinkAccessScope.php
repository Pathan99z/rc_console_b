<?php

namespace App\Support\DemoLinks;

use App\Models\DemoLink;
use App\Models\Organization;
use App\Models\User;
use App\Repositories\OrganizationRepository;
use App\Services\Auth\AccessScopeService;
use App\Services\Auth\PermissionResolverService;
use App\Support\DomainConstants;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class DemoLinkAccessScope
{
    public function __construct(
        private readonly AccessScopeService $accessScopeService,
        private readonly OrganizationRepository $organizationRepository,
        private readonly PermissionResolverService $permissionResolver,
    ) {}

    public function hasTenantWideAccess(User $actor): bool
    {
        return $actor->isGlobalAdmin()
            || $actor->isCompanyAdmin()
            || $actor->isFinanceAdmin()
            || $this->permissionResolver->can($actor, 'demo_links.manage_all');
    }

    /**
     * @return list<int>
     */
    public function visibleOrganizationIds(User $actor): array
    {
        if ($this->hasTenantWideAccess($actor)) {
            return [];
        }

        if ($actor->isPartnerChannelUser() && ! $actor->isResellerRole()) {
            return $this->accessScopeService->visibleChannelOrgIds($actor);
        }

        if ($actor->isResellerRole()) {
            $orgId = (int) ($actor->primaryOrganizationId() ?? 0);

            return $orgId > 0 ? [$orgId] : [];
        }

        $companyOrgId = $this->organizationRepository->firstCompanyOrganizationIdForTenant((int) $actor->tenant_id);

        return $companyOrgId ? [$companyOrgId] : [];
    }

    public function assertOwnerOrganizationAllowed(User $actor, int $ownerOrganizationId): void
    {
        $org = $this->organizationRepository->findByIdInTenant($ownerOrganizationId, (int) $actor->tenant_id);
        if (! $org) {
            throw ValidationException::withMessages([
                'owner_organization_id' => ['Invalid owner organization.'],
            ]);
        }

        if ($this->hasTenantWideAccess($actor)) {
            return;
        }

        $visible = $this->visibleOrganizationIds($actor);
        if ($visible === [] || ! in_array($ownerOrganizationId, $visible, true)) {
            throw ValidationException::withMessages([
                'owner_organization_id' => [DomainConstants::MSG_UNAUTHORIZED_SCOPE],
            ]);
        }
    }

    /**
     * @param  list<array{organization_id: int, include_children?: bool, visibility_type?: string}>  $visibility
     */
    public function assertVisibilityOrganizationsAllowed(User $actor, array $visibility): void
    {
        if (! $this->permissionResolver->can($actor, 'demo_links.share') && ! $this->hasTenantWideAccess($actor)) {
            throw ValidationException::withMessages([
                'visibility' => ['You are not allowed to configure demo link sharing.'],
            ]);
        }

        $shareable = array_flip($this->shareableOrganizationIds($actor));

        foreach ($visibility as $row) {
            $orgId = (int) ($row['organization_id'] ?? 0);
            if ($orgId <= 0 || ! isset($shareable[$orgId])) {
                throw ValidationException::withMessages([
                    'visibility' => ['One or more shared organizations are outside your allowed scope.'],
                ]);
            }
        }
    }

    /**
     * @return list<int>
     */
    public function shareableOrganizationIds(User $actor): array
    {
        if ($this->hasTenantWideAccess($actor)) {
            return Organization::query()
                ->where('tenant_id', $actor->tenant_id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($actor->isPartnerChannelUser() && ! $actor->isResellerRole()) {
            return $this->organizationRepository->channelTreeOrganizationIds(
                (int) ($actor->primaryOrganizationId() ?? 0)
            );
        }

        if ($actor->isResellerRole()) {
            return [(int) ($actor->primaryOrganizationId() ?? 0)];
        }

        $companyOrgId = $this->organizationRepository->firstCompanyOrganizationIdForTenant((int) $actor->tenant_id);

        return $companyOrgId ? [$companyOrgId] : [];
    }

    public function assertCanViewDemoLink(User $actor, DemoLink $link): void
    {
        if ($actor->isGlobalAdmin()) {
            return;
        }

        if ((int) $link->tenant_id !== (int) $actor->tenant_id) {
            throw new ModelNotFoundException('Demo link not found.');
        }

        if ($this->hasTenantWideAccess($actor)) {
            return;
        }

        if ($this->linkVisibleToActor($actor, $link)) {
            return;
        }

        throw new ModelNotFoundException('Demo link not found.');
    }

    public function assertCanManageDemoLink(User $actor, DemoLink $link): void
    {
        $this->assertCanViewDemoLink($actor, $link);

        if ($this->hasTenantWideAccess($actor)) {
            return;
        }

        if ((int) $link->created_by_user_id === (int) $actor->id) {
            return;
        }

        $visible = $this->visibleOrganizationIds($actor);
        if (in_array((int) $link->owner_organization_id, $visible, true)
            && ($actor->isPartnerAdmin() || $actor->currentRoleCode() === \App\Models\Role::CODE_RESELLER_ADMIN)) {
            return;
        }

        throw ValidationException::withMessages([
            'demo_link' => [DomainConstants::MSG_UNAUTHORIZED_SCOPE],
        ]);
    }

    public function canRevealCredentials(User $actor, DemoLink $link): bool
    {
        try {
            $this->assertCanManageDemoLink($actor, $link);

            return true;
        } catch (\Throwable) {
            // Continue: visibility-target orgs may reveal without full manage rights.
        }

        try {
            $this->assertCanViewDemoLink($actor, $link);
        } catch (\Throwable) {
            return false;
        }

        return $this->actorBeneficiaryOfVisibilityRows($actor, $link);
    }

    /**
     * True when the actor's scoped organizations are explicitly granted access via a visibility row
     * (exact org or include_children coverage).
     */
    private function actorBeneficiaryOfVisibilityRows(User $actor, DemoLink $link): bool
    {
        $scopeIds = $this->visibleOrganizationIds($actor);
        if ($scopeIds === []) {
            return false;
        }

        $scopeFlip = array_flip($scopeIds);
        $link->loadMissing('visibilities');

        foreach ($link->visibilities as $visibility) {
            $orgId = (int) $visibility->organization_id;

            if ($visibility->include_children) {
                foreach (array_merge([$orgId], $this->organizationRepository->descendantIds($orgId)) as $coveredId) {
                    if (isset($scopeFlip[$coveredId])) {
                        return true;
                    }
                }
            } elseif (isset($scopeFlip[$orgId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Builder<DemoLink>  $query
     */
    public function applyListScope(Builder $query, User $actor): void
    {
        if ($actor->isGlobalAdmin()) {
            return;
        }

        $query->where('tenant_id', $actor->tenant_id);

        if ($this->hasTenantWideAccess($actor)) {
            return;
        }

        $orgIds = $this->visibleOrganizationIds($actor);
        if ($orgIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $outer) use ($orgIds): void {
            $outer->whereIn('owner_organization_id', $orgIds)
                ->orWhereHas('visibilities', function (Builder $v) use ($orgIds): void {
                    $v->whereIn('organization_id', $orgIds);
                })
                ->orWhereHas('visibilities', function (Builder $v) use ($orgIds): void {
                    $v->where('include_children', true)
                        ->where(function (Builder $inner) use ($orgIds): void {
                            foreach ($orgIds as $orgId) {
                                $inner->orWhereIn('organization_id', $this->ancestorOrganizationIds($orgId));
                            }
                        });
                });
        });
    }

    private function linkVisibleToActor(User $actor, DemoLink $link): bool
    {
        $orgIds = $this->visibleOrganizationIds($actor);
        if ($orgIds === []) {
            return false;
        }

        if (in_array((int) $link->owner_organization_id, $orgIds, true)) {
            return true;
        }

        $link->loadMissing('visibilities');

        foreach ($link->visibilities as $visibility) {
            if (in_array((int) $visibility->organization_id, $orgIds, true)) {
                return true;
            }

            if ($visibility->include_children) {
                $descendants = $this->organizationRepository->descendantIds((int) $visibility->organization_id);
                foreach ($orgIds as $actorOrgId) {
                    if (in_array($actorOrgId, $descendants, true) || (int) $visibility->organization_id === $actorOrgId) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function ancestorOrganizationIds(int $organizationId): array
    {
        $ancestors = [];
        $current = Organization::query()->find($organizationId);

        while ($current && $current->parent_organization_id) {
            $ancestors[] = (int) $current->parent_organization_id;
            $current = Organization::query()->find($current->parent_organization_id);
        }

        $ancestors[] = $organizationId;

        return array_values(array_unique($ancestors));
    }
}
