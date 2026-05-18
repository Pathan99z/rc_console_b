<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\DomainConstants;
use App\Support\PartnerScopeResolver;
use Illuminate\Database\Eloquent\Builder;

class AccessScopeService
{
    public function __construct(private readonly PartnerScopeResolver $partnerScopeResolver) {}

    public function isGlobal(User $actor): bool
    {
        return $actor->isGlobalAdmin();
    }

    public function isCompany(User $actor): bool
    {
        return $actor->isCompanyAdmin();
    }

    /**
     * @return list<int>
     */
    public function visibleChannelOrgIds(User $actor): array
    {
        return $this->partnerScopeResolver->visibleChannelOrganizationIds($actor);
    }

    public function applyOwnerTeamScope(Builder $query, User $actor, string $ownerColumn, ?string $secondaryOwnerColumn = null): void
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return;
        }

        $query->where(function (Builder $inner) use ($actor, $ownerColumn, $secondaryOwnerColumn): void {
            $inner->where($ownerColumn, $actor->id);
            if ($secondaryOwnerColumn !== null) {
                $inner->orWhere($secondaryOwnerColumn, $actor->id);
            }

            if ((int) $actor->data_scope !== DomainConstants::DATA_SCOPE_TEAM || $actor->team_id === null) {
                return;
            }

            $teamUserIds = User::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('team_id', $actor->team_id)
                ->pluck('id')
                ->all();

            if ($teamUserIds === []) {
                return;
            }

            $inner->orWhereIn($ownerColumn, $teamUserIds);
            if ($secondaryOwnerColumn !== null) {
                $inner->orWhereIn($secondaryOwnerColumn, $teamUserIds);
            }
        });
    }

    public function applyPartnerDealScope(Builder $query, User $actor, string $partnerOrgColumn = 'partner_organization_id'): void
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return;
        }

        $orgIds = $this->visibleChannelOrgIds($actor);
        if ($orgIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn($partnerOrgColumn, $orgIds);
    }

    /**
     * Applies channel org visibility with legacy partner_organization_id fallback on the same table.
     *
     * @param  bool  $allowLegacyPartnerColumn  When false, CRM list APIs ignore partner_organization_id-only
     *                                           rows (prevents company-owned records leaking via deal links).
     */
    public function applyChannelOrganizationScope(
        Builder $query,
        User $actor,
        string $channelColumn = 'channel_organization_id',
        ?string $legacyPartnerColumn = 'partner_organization_id',
        bool $allowLegacyPartnerColumn = true,
    ): void {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return;
        }

        $orgIds = $this->visibleChannelOrgIds($actor);
        if ($orgIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $inner) use ($orgIds, $channelColumn, $legacyPartnerColumn, $allowLegacyPartnerColumn): void {
            $inner->whereIn($channelColumn, $orgIds);
            if ($allowLegacyPartnerColumn && $legacyPartnerColumn !== null) {
                $inner->orWhere(function (Builder $legacy) use ($orgIds, $channelColumn, $legacyPartnerColumn): void {
                    $legacy->whereNull($channelColumn)->whereIn($legacyPartnerColumn, $orgIds);
                });
            }
        });
    }
}
