<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Repositories\OrganizationRepository;

class OrganizationDashboardPolicy
{
    public function __construct(private readonly OrganizationRepository $organizationRepository) {}

    public function view(User $user, Organization $organization): bool
    {
        if ($user->isGlobalAdmin()) {
            return in_array($organization->type, [Organization::TYPE_PARTNER, Organization::TYPE_RESELLER], true);
        }

        if ((int) $organization->tenant_id !== (int) $user->tenant_id) {
            return false;
        }

        if ($user->isCompanyAdmin()) {
            return in_array($organization->type, [Organization::TYPE_PARTNER, Organization::TYPE_RESELLER], true);
        }

        if ($user->currentRoleCode() === Role::CODE_PARTNER_ADMIN) {
            $tree = $this->organizationRepository->channelTreeOrganizationIds((int) ($user->primaryOrganizationId() ?? 0));

            return in_array((int) $organization->id, $tree, true);
        }

        if ($user->currentRoleCode() === Role::CODE_RESELLER_ADMIN) {
            return (int) $organization->id === (int) ($user->primaryOrganizationId() ?? 0);
        }

        return false;
    }
}
