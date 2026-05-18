<?php

namespace App\Services\Dashboard;

use App\Models\Role;
use App\Models\User;
use App\Services\Dashboard\Builders\CompanyDashboardBuilder;
use App\Services\Dashboard\Builders\GlobalDashboardBuilder;
use App\Services\Dashboard\Builders\PartnerDashboardBuilder;
use App\Services\Dashboard\Builders\ResellerDashboardBuilder;
use App\Support\Cache\DashboardCacheManager;
use Illuminate\Auth\Access\AuthorizationException;

class LoginDashboardService
{
    public function __construct(
        private readonly GlobalDashboardBuilder $globalBuilder,
        private readonly CompanyDashboardBuilder $companyBuilder,
        private readonly PartnerDashboardBuilder $partnerBuilder,
        private readonly ResellerDashboardBuilder $resellerBuilder,
        private readonly DashboardCacheManager $dashboardCache,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function resolve(User $actor, array $filters = []): array
    {
        $profile = $this->loginDashboardProfile($actor);

        if ($profile === null) {
            throw new AuthorizationException('Login dashboard is not available for this role.');
        }

        $from = (string) ($filters['from'] ?? 'all');
        $to = (string) ($filters['to'] ?? 'all');
        $version = $this->dashboardCache->loginDashboardVersion($actor);

        return match ($profile) {
            Role::CODE_GLOBAL_ADMIN => $this->dashboardCache->remember(
                "login-dashboard:global:{$from}:{$to}:v:{$version}",
                (int) config('enterprise_cache.ttl.login_dashboard_global', 600),
                fn () => $this->globalBuilder->build($actor, $filters)
            ),
            Role::CODE_COMPANY_ADMIN => $this->dashboardCache->remember(
                sprintf('login-dashboard:tenant:%d:company:%s:%s:v:%s', (int) $actor->tenant_id, $from, $to, $version),
                (int) config('enterprise_cache.ttl.login_dashboard_company', 300),
                fn () => $this->companyBuilder->build($actor, $filters)
            ),
            Role::CODE_PARTNER_ADMIN => $this->dashboardCache->remember(
                sprintf(
                    'login-dashboard:tenant:%d:partner:%d:user:%d:%s:%s:v:%s',
                    (int) $actor->tenant_id,
                    (int) ($actor->primaryOrganizationId() ?? 0),
                    (int) $actor->id,
                    $from,
                    $to,
                    $version
                ),
                (int) config('enterprise_cache.ttl.login_dashboard_partner', 300),
                fn () => $this->partnerBuilder->build($actor, $filters)
            ),
            Role::CODE_RESELLER_ADMIN => $this->dashboardCache->remember(
                sprintf(
                    'login-dashboard:tenant:%d:reseller:%d:user:%d:%s:%s:v:%s',
                    (int) $actor->tenant_id,
                    (int) ($actor->primaryOrganizationId() ?? 0),
                    (int) $actor->id,
                    $from,
                    $to,
                    $version
                ),
                (int) config('enterprise_cache.ttl.login_dashboard_reseller', 300),
                fn () => $this->resellerBuilder->build($actor, $filters)
            ),
            default => throw new AuthorizationException('Login dashboard is not available for this role.'),
        };
    }

    public function loginDashboardProfile(User $actor): ?string
    {
        return match ($actor->currentRoleCode()) {
            Role::CODE_GLOBAL_ADMIN => Role::CODE_GLOBAL_ADMIN,
            Role::CODE_COMPANY_ADMIN => Role::CODE_COMPANY_ADMIN,
            Role::CODE_PARTNER_ADMIN => Role::CODE_PARTNER_ADMIN,
            Role::CODE_RESELLER_ADMIN => Role::CODE_RESELLER_ADMIN,
            default => null,
        };
    }
}
