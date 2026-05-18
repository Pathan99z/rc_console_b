<?php

namespace App\Services\Dashboard;

use App\Models\Role;
use App\Models\User;
use App\Services\Dashboard\Builders\CompanyDashboardBuilder;
use App\Services\Dashboard\Builders\GlobalDashboardBuilder;
use App\Services\Dashboard\Builders\PartnerDashboardBuilder;
use App\Services\Dashboard\Builders\ResellerDashboardBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;

class LoginDashboardService
{
    private const TTL_GLOBAL = 600;

    private const TTL_COMPANY = 300;

    private const TTL_PARTNER = 300;

    private const TTL_RESELLER = 300;

    public function __construct(
        private readonly GlobalDashboardBuilder $globalBuilder,
        private readonly CompanyDashboardBuilder $companyBuilder,
        private readonly PartnerDashboardBuilder $partnerBuilder,
        private readonly ResellerDashboardBuilder $resellerBuilder,
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

        return match ($profile) {
            Role::CODE_GLOBAL_ADMIN => Cache::remember(
                "login-dashboard:global:{$from}:{$to}",
                self::TTL_GLOBAL,
                fn () => $this->globalBuilder->build($actor, $filters)
            ),
            Role::CODE_COMPANY_ADMIN => Cache::remember(
                sprintf('login-dashboard:tenant:%d:company:%s:%s', (int) $actor->tenant_id, $from, $to),
                self::TTL_COMPANY,
                fn () => $this->companyBuilder->build($actor, $filters)
            ),
            Role::CODE_PARTNER_ADMIN => Cache::remember(
                sprintf(
                    'login-dashboard:tenant:%d:partner:%d:user:%d:%s:%s',
                    (int) $actor->tenant_id,
                    (int) ($actor->primaryOrganizationId() ?? 0),
                    (int) $actor->id,
                    $from,
                    $to
                ),
                self::TTL_PARTNER,
                fn () => $this->partnerBuilder->build($actor, $filters)
            ),
            Role::CODE_RESELLER_ADMIN => Cache::remember(
                sprintf(
                    'login-dashboard:tenant:%d:reseller:%d:user:%d:%s:%s',
                    (int) $actor->tenant_id,
                    (int) ($actor->primaryOrganizationId() ?? 0),
                    (int) $actor->id,
                    $from,
                    $to
                ),
                self::TTL_RESELLER,
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
