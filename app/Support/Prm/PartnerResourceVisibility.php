<?php

namespace App\Support\Prm;

use App\Models\Collateral;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Partner / reseller visibility for PRM Resource Center collaterals.
 *
 * Future: program-tier visibility (e.g. collateral_partner_programs) can extend
 * {@see self::applyPartnerConsumptionScope()} without changing public partner API contracts.
 */
final class PartnerResourceVisibility
{
    public static function applyPartnerListScope(Builder $query, User $actor): void
    {
        $query->where('collaterals.status', Collateral::STATUS_ACTIVE);

        if ($actor->isResellerRole()) {
            $query->where('collaterals.reseller_visible', true);

            return;
        }

        if ($actor->isPartnerChannelUser()) {
            $query->where('collaterals.partner_visible', true);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    public static function canPartnerAccessCollateral(User $actor, Collateral $collateral): bool
    {
        if (! $collateral->isPrmActive()) {
            return false;
        }

        if ($actor->isResellerRole()) {
            return (bool) $collateral->reseller_visible;
        }

        if ($actor->isPartnerChannelUser()) {
            return (bool) $collateral->partner_visible;
        }

        return false;
    }
}
