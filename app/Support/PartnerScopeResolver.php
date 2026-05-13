<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use App\Repositories\OrganizationRepository;

readonly class PartnerScopeResolver
{
    public function __construct(private OrganizationRepository $organizationRepository) {}

    /**
     * Organization ids (partner/reseller root + descendants) visible for channel pipeline / deals.
     *
     * @return list<int>
     */
    public function visibleChannelOrganizationIds(User $actor): array
    {
        $rootId = (int) ($actor->primaryOrganizationId() ?? 0);
        if ($rootId <= 0) {
            return [];
        }

        if ($actor->currentRoleCode() === Role::CODE_PARTNER_ADMIN
            || in_array($actor->currentRoleCode(), [
                Role::CODE_PARTNER_SALES_MANAGER,
                Role::CODE_PARTNER_SALES_CONSULTANT,
            ], true)) {
            return $this->organizationRepository->channelTreeOrganizationIds($rootId);
        }

        if ($actor->isResellerRole()) {
            return [$rootId];
        }

        return [];
    }
}
