<?php

namespace App\Support\Prm;

use App\Models\Organization;
use App\Models\Payout;
use App\Models\User;
use App\Services\Auth\AccessScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PayoutAccessScope
{
    public function __construct(private readonly AccessScopeService $accessScopeService) {}

    /**
     * @return list<int>
     */
    public function visibleBeneficiaryOrgIds(User $actor): array
    {
        if ($actor->isGlobalAdmin()) {
            return [];
        }

        if ($actor->isCompanyAdmin() || $actor->isFinanceAdmin()) {
            return [];
        }

        if ($actor->isPartnerPortalEligible()) {
            return $this->accessScopeService->visibleChannelOrgIds($actor);
        }

        return [];
    }

    public function canManageFinance(User $actor): bool
    {
        return $actor->isGlobalAdmin()
            || $actor->isCompanyAdmin()
            || $actor->isFinanceAdmin();
    }

    public function assertCanViewPayout(User $actor, Payout $payout): void
    {
        if ($actor->isGlobalAdmin()) {
            return;
        }

        if ((int) $payout->tenant_id !== (int) $actor->tenant_id) {
            throw new ModelNotFoundException('Payout not found.');
        }

        if ($this->canManageFinance($actor)) {
            return;
        }

        $visible = $this->visibleBeneficiaryOrgIds($actor);
        if ($visible === [] || ! in_array((int) $payout->beneficiary_organization_id, $visible, true)) {
            throw new ModelNotFoundException('Payout not found.');
        }
    }

    public function assertCanManageFinance(User $actor): void
    {
        if (! $this->canManageFinance($actor)) {
            throw new AuthorizationException('Finance access required.');
        }
    }
}
