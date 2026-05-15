<?php

namespace App\Services\Prm;

use App\Models\Collateral;
use App\Models\CollateralDownload;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aggregated PRM resource analytics for admins. Tenant-isolated; global admin passes tenant_id.
 */
class PrmResourceAnalyticsService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summarize(User $actor, array $filters): array
    {
        $tenantId = $this->resolveTenantId($actor, $filters);

        if (! empty($filters['collateral_id'])) {
            $cid = (int) $filters['collateral_id'];
            if (! Collateral::query()->where('tenant_id', $tenantId)->whereKey($cid)->exists()) {
                throw ValidationException::withMessages([
                    'collateral_id' => ['Invalid collateral for tenant.'],
                ]);
            }
        }

        if (! empty($filters['partner_organization_id'])) {
            $oid = (int) $filters['partner_organization_id'];
            if (! Organization::query()->where('tenant_id', $tenantId)->whereKey($oid)->exists()) {
                throw ValidationException::withMessages([
                    'partner_organization_id' => ['Invalid organization for tenant.'],
                ]);
            }
        }

        $collateralBase = Collateral::query()->where('tenant_id', $tenantId);

        $totalResources = (clone $collateralBase)->count();
        $activeResources = (clone $collateralBase)->where('status', Collateral::STATUS_ACTIVE)->count();
        $inactiveResources = (clone $collateralBase)->where('status', Collateral::STATUS_INACTIVE)->count();

        $downloadBase = $this->filteredDownloadsQuery($tenantId, $filters);
        $totalDownloads = (clone $downloadBase)->count();

        $topResources = (clone $downloadBase)
            ->select('collateral_id', DB::raw('count(*) as download_count'))
            ->groupBy('collateral_id')
            ->orderByDesc('download_count')
            ->limit(10)
            ->get()
            ->map(function ($row): array {
                $c = Collateral::query()->find($row->collateral_id);

                return [
                    'collateral_id' => (int) $row->collateral_id,
                    'title' => $c?->name,
                    'download_count' => (int) $row->download_count,
                ];
            })
            ->values()
            ->all();

        $topPartnerDownloaders = (clone $downloadBase)
            ->whereNotNull('partner_organization_id')
            ->select('partner_organization_id', DB::raw('count(*) as download_count'))
            ->groupBy('partner_organization_id')
            ->orderByDesc('download_count')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'partner_organization_id' => (int) $row->partner_organization_id,
                'download_count' => (int) $row->download_count,
            ])
            ->values()
            ->all();

        return [
            'total_resources' => $totalResources,
            'active_resources' => $activeResources,
            'inactive_resources' => $inactiveResources,
            'total_downloads' => $totalDownloads,
            'top_resources' => $topResources,
            'top_partner_downloaders' => $topPartnerDownloaders,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CollateralDownload>
     */
    private function filteredDownloadsQuery(int $tenantId, array $filters)
    {
        $q = CollateralDownload::query()->where('tenant_id', $tenantId);

        if (! empty($filters['from'])) {
            $q->where('downloaded_at', '>=', $filters['from'].' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $q->where('downloaded_at', '<=', $filters['to'].' 23:59:59');
        }
        if (! empty($filters['collateral_id'])) {
            $q->where('collateral_id', (int) $filters['collateral_id']);
        }
        if (! empty($filters['partner_organization_id'])) {
            $q->where('partner_organization_id', (int) $filters['partner_organization_id']);
        }

        return $q;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function resolveTenantId(User $actor, array $filters): int
    {
        if ($actor->isGlobalAdmin()) {
            if (empty($filters['tenant_id'])) {
                throw ValidationException::withMessages([
                    'tenant_id' => ['Tenant is required for analytics.'],
                ]);
            }

            return (int) $filters['tenant_id'];
        }

        return (int) $actor->tenant_id;
    }
}
