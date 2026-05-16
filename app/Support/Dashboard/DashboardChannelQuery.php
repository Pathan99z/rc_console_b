<?php

namespace App\Support\Dashboard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Applies channel organization visibility for dashboard aggregates.
 */
trait DashboardChannelQuery
{
    /**
     * @param  list<int>  $organizationIds
     */
    protected function whereChannelScope(
        Builder|QueryBuilder $query,
        int $tenantId,
        array $organizationIds,
        string $channelColumn,
        ?string $legacyColumn = null,
        ?string $tablePrefix = null,
    ): Builder|QueryBuilder {
        $prefix = $tablePrefix ? $tablePrefix.'.' : '';
        $channel = $prefix.$channelColumn;

        return $query
            ->where($prefix.'tenant_id', $tenantId)
            ->where(function ($inner) use ($organizationIds, $channel, $legacyColumn, $prefix): void {
                $inner->whereIn($channel, $organizationIds);
                if ($legacyColumn !== null) {
                    $legacy = $prefix.$legacyColumn;
                    $inner->orWhere(function ($legacyQ) use ($organizationIds, $channel, $legacy): void {
                        $legacyQ->whereNull($channel)->whereIn($legacy, $organizationIds);
                    });
                }
            });
    }

    /**
     * @param  list<int>  $organizationIds
     */
    protected function whereDealChannelScope(Builder|QueryBuilder $query, int $tenantId, array $organizationIds, string $dealAlias = 'deals'): Builder|QueryBuilder
    {
        return $this->whereChannelScope(
            $query,
            $tenantId,
            $organizationIds,
            'channel_organization_id',
            'partner_organization_id',
            $dealAlias
        );
    }
}
