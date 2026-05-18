<?php

declare(strict_types=1);

namespace App\Services\Cache;

use App\Models\Organization;
use App\Models\User;
use App\Repositories\OrganizationRepository;
use App\Support\Cache\DashboardCacheManager;
use App\Support\Cache\NotificationCountCache;
use App\Support\Cache\TenantListCache;
use App\Support\Cache\UserBootstrapCache;

/**
 * Central invalidation for list caches, dashboards, notifications, and user bootstrap.
 */
final class CacheInvalidationService
{
    public function __construct(
        private readonly TenantListCache $tenantListCache,
        private readonly DashboardCacheManager $dashboardCache,
        private readonly NotificationCountCache $notificationCountCache,
        private readonly UserBootstrapCache $userBootstrapCache,
        private readonly OrganizationRepository $organizationRepository,
    ) {}

    public function bumpListVersion(string $module, ?int $tenantId): void
    {
        $this->tenantListCache->bumpVersion($module, $tenantId);
    }

    /**
     * @param  list<string>  $modules
     */
    public function bumpListVersions(?int $tenantId, array $modules): void
    {
        foreach ($modules as $module) {
            $this->bumpListVersion($module, $tenantId);
        }
    }

    public function afterCrmMutation(int $tenantId, ?int $channelOrganizationId = null): void
    {
        $this->bumpListVersions($tenantId, ['contacts', 'companies', 'deals', 'quotes']);
        $this->invalidateTenantDashboards($tenantId, $channelOrganizationId);
    }

    public function afterDealMutation(int $tenantId, ?int $channelOrganizationId = null): void
    {
        $this->bumpListVersions($tenantId, ['deals', 'quotes']);
        $this->invalidateTenantDashboards($tenantId, $channelOrganizationId);
    }

    public function afterQuoteMutation(int $tenantId, ?int $channelOrganizationId = null): void
    {
        $this->bumpListVersion('quotes', $tenantId);
        $this->invalidateTenantDashboards($tenantId, $channelOrganizationId);
    }

    public function afterPaymentMutation(int $tenantId, ?int $channelOrganizationId = null): void
    {
        $this->bumpListVersion('quotes', $tenantId);
        $this->invalidateTenantDashboards($tenantId, $channelOrganizationId);
    }

    public function afterProductMutation(int $tenantId): void
    {
        $this->bumpListVersion('products', $tenantId);
        $this->dashboardCache->invalidateTenantEpoch($tenantId);
        $this->dashboardCache->invalidateLoginTenant($tenantId);
    }

    public function afterCollateralMutation(int $tenantId): void
    {
        $this->bumpListVersion('collaterals', $tenantId);
        $this->dashboardCache->invalidateTenantEpoch($tenantId);
        $this->dashboardCache->invalidateLoginTenant($tenantId);
    }

    public function afterPipelineMutation(int $tenantId): void
    {
        $this->bumpListVersions($tenantId, ['pipelines', 'deals']);
        $this->invalidateTenantDashboards($tenantId, null);
    }

    public function afterLicenseMutation(int $tenantId, int $organizationId): void
    {
        $this->invalidateOrganizationDashboard($tenantId, $organizationId);
        $this->dashboardCache->invalidateLoginTenant($tenantId);
    }

    public function afterOrganizationMutation(int $tenantId, int $organizationId): void
    {
        $this->invalidateOrganizationDashboard($tenantId, $organizationId);
        $this->dashboardCache->invalidateTenantEpoch($tenantId);
        $this->dashboardCache->invalidateLoginTenant($tenantId);
        $this->dashboardCache->invalidateLoginGlobal();
    }

    public function afterUserPermissionMutation(User $user): void
    {
        $this->userBootstrapCache->invalidate($user);
        if ($user->tenant_id !== null) {
            $this->dashboardCache->invalidateLoginUser((int) $user->tenant_id, (int) $user->id);
        }
    }

    public function afterNotificationMutation(User $user): void
    {
        $this->notificationCountCache->invalidate($user);
    }

    public function invalidateOrganizationDashboard(int $tenantId, int $organizationId): void
    {
        $this->dashboardCache->invalidateOrganization($tenantId, $organizationId);
    }

    public function invalidateTenantDashboards(int $tenantId, ?int $channelOrganizationId): void
    {
        $this->dashboardCache->invalidateTenantEpoch($tenantId);
        $this->dashboardCache->invalidateLoginTenant($tenantId);

        if ($channelOrganizationId !== null && $channelOrganizationId > 0) {
            $this->invalidateOrganizationTree($tenantId, $channelOrganizationId);
        }
    }

    private function invalidateOrganizationTree(int $tenantId, int $organizationId): void
    {
        $this->dashboardCache->invalidateOrganization($tenantId, $organizationId);

        $org = Organization::query()->withoutGlobalScopes()->whereKey($organizationId)->first();
        if ($org === null) {
            return;
        }

        if ($org->parent_organization_id !== null) {
            $this->dashboardCache->invalidateOrganization($tenantId, (int) $org->parent_organization_id);
        }

        if ($org->type === Organization::TYPE_PARTNER) {
            foreach ($this->organizationRepository->channelTreeOrganizationIds($organizationId) as $orgId) {
                $this->dashboardCache->invalidateOrganization($tenantId, $orgId);
            }
        }
    }
}
