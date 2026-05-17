<?php

namespace App\Services\Notifications;

use App\Models\Deal;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationAssignment;

final class NotificationRecipientResolver
{
    /**
     * @return list<int>
     */
    public function uniqueInts(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }

    /**
     * @param  list<string>  $roleCodes
     * @return list<int>
     */
    public function userIdsWithRolesInTenant(int $tenantId, array $roleCodes): array
    {
        if ($roleCodes === []) {
            return [];
        }

        return User::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', $roleCodes)
            ->where('status', User::STATUS_ACTIVE)
            ->pluck('id')
            ->all();
    }

    /**
     * @return list<int>
     */
    public function companyAndFinanceUserIds(int $tenantId): array
    {
        return $this->userIdsWithRolesInTenant($tenantId, [
            Role::CODE_COMPANY_ADMIN,
            Role::CODE_FINANCE_ADMIN,
        ]);
    }

    /**
     * KAM analogue: partner sales managers (+ company admins for tenant oversight).
     *
     * @return list<int>
     */
    public function partnerKamAudienceForTenantOrg(int $tenantId, Organization $partnerOrg): array
    {
        $kam = User::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', [Role::CODE_PARTNER_SALES_MANAGER])
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('organizationAssignment', function ($q) use ($partnerOrg): void {
                $q->where('organization_id', $partnerOrg->id);
            })
            ->pluck('id')
            ->all();

        return $this->uniqueInts(array_merge(
            $this->companyAndFinanceUserIds($tenantId),
            $kam
        ));
    }

    /**
     * @return list<int>
     */
    public function adminsForPartnerOrganizationTree(int $tenantId, int $partnerOrganizationId): array
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->where('role', Role::CODE_PARTNER_ADMIN)
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('organizationAssignment', function ($q) use ($partnerOrganizationId): void {
                $q->where('organization_id', $partnerOrganizationId);
            })
            ->pluck('id')
            ->all();
    }

    /**
     * @return list<int>
     */
    public function resellerAdminsForOrganization(int $tenantId, int $resellerOrganizationId): array
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->where('role', Role::CODE_RESELLER_ADMIN)
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('organizationAssignment', function ($q) use ($resellerOrganizationId): void {
                $q->where('organization_id', $resellerOrganizationId);
            })
            ->pluck('id')
            ->all();
    }

    public function managerWithinTenant(?User $subject): ?User
    {
        if (! $subject || ! $subject->manager_id || (int) $subject->tenant_id <= 0) {
            return null;
        }

        $manager = User::query()->whereKey((int) $subject->manager_id)->first();
        if (! $manager || (int) $manager->tenant_id !== (int) $subject->tenant_id) {
            return null;
        }
        if ((int) $manager->status !== User::STATUS_ACTIVE) {
            return null;
        }

        return $manager;
    }

    /**
     * @return list<int>
     */
    public function quoteOwnersAndFinance(Quote $quote): array
    {
        return $this->uniqueInts(array_merge(
            [(int) $quote->created_by_user_id],
            $this->companyAndFinanceUserIds((int) $quote->tenant_id)
        ));
    }

    /**
     * @return list<int>
     */
    public function dealOwnerAndFinance(Deal $deal): array
    {
        return $this->uniqueInts(array_merge(
            [(int) $deal->owner_user_id],
            $this->companyAndFinanceUserIds((int) $deal->tenant_id)
        ));
    }

    /**
     * Quote creator + finance admins only (omit partner roles from quote-sent style events).
     *
     * @return list<int>
     */
    public function quoteCreatorAndFinanceStaff(Quote $quote): array
    {
        return $this->uniqueInts(array_merge(
            [(int) $quote->created_by_user_id],
            $this->companyAndFinanceUserIds((int) $quote->tenant_id)
        ));
    }

    /**
     * @return list<int>
     */
    public function dealOwnerOnlyIds(Deal $deal): array
    {
        return $this->uniqueInts([(int) $deal->owner_user_id]);
    }

    /** Reseller stakeholder audience: reseller admins + parent partner admins + company/finance oversight. */
    public function resellerStakeholderUserIds(int $tenantId, Organization $reseller): array
    {
        $partnerId = $reseller->parent_organization_id !== null ? (int) $reseller->parent_organization_id : null;
        $partnerAdminIds = $partnerId !== null ? $this->adminsForPartnerOrganizationTree($tenantId, $partnerId) : [];

        return $this->uniqueInts(array_merge(
            $partnerAdminIds,
            $this->resellerAdminsForOrganization($tenantId, (int) $reseller->id),
            $this->companyAndFinanceUserIds($tenantId)
        ));
    }
}
