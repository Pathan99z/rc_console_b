<?php

namespace App\Services\Prm;

use App\Models\User;
use App\Services\Organization\OrganizationDashboardService;

/**
 * Partner/reseller portal shell — delegates analytics to shared dashboard engine.
 * Preserves legacy summary shape for backward-compatible frontend integrations.
 */
class PrmDashboardService
{
    public function __construct(
        private readonly OrganizationDashboardService $organizationDashboardService,
    ) {}

    /**
     * Legacy partner portal summary (backward compatible).
     *
     * @return array<string, mixed>
     */
    public function partnerSummary(User $actor): array
    {
        $overview = $this->organizationDashboardService->overviewForActor($actor);
        $kpis = $overview['kpis'] ?? [];

        $orgId = (int) ($overview['organization']['id'] ?? $actor->primaryOrganizationId() ?? 0);

        return [
            'partner_organization_id' => $orgId,
            'counts' => [
                'leads' => (int) ($kpis['leads'] ?? 0),
                'deals' => (int) ($kpis['crm']['deals'] ?? 0),
                'quotes' => (int) ($kpis['crm']['quotes'] ?? 0),
            ],
            'commission_pending_total' => (float) ($kpis['commissions']['pending'] ?? 0),
            'license_units_available' => (int) ($kpis['licenses']['available'] ?? 0),
            'pipeline_value' => (float) ($kpis['deals']['pipeline_value'] ?? 0),
        ];
    }

    /**
     * Full dashboard payload for partner self-service portal.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function partnerDashboard(User $actor, array $filters = []): array
    {
        return [
            'summary' => $this->partnerSummary($actor),
            'dashboard' => $this->organizationDashboardService->overviewForActor($actor, $filters),
        ];
    }

    /**
     * Full dashboard payload for reseller self-service portal.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function resellerDashboard(User $actor, array $filters = []): array
    {
        return [
            'dashboard' => $this->organizationDashboardService->overviewForActor($actor, $filters),
        ];
    }

    /**
     * @return list<array{key: string, label: string, route: string}>
     */
    public function navigationItems(): array
    {
        return [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'route' => '/partner/dashboard'],
            ['key' => 'leads', 'label' => 'Lead registration', 'route' => '/partner/leads'],
            ['key' => 'opportunities', 'label' => 'Opportunities', 'route' => '/partner/opportunities'],
            ['key' => 'quotes', 'label' => 'Quotes', 'route' => '/partner/quotes'],
            ['key' => 'resources', 'label' => 'Resource center', 'route' => '/partner/resources'],
            ['key' => 'resellers', 'label' => 'Reseller management', 'route' => '/partner/resellers'],
            ['key' => 'commissions', 'label' => 'Commissions', 'route' => '/partner/commissions'],
            ['key' => 'licenses', 'label' => 'License inventory', 'route' => '/partner/licenses'],
            ['key' => 'payments', 'label' => 'Payment history', 'route' => '/partner/payments'],
            ['key' => 'payouts', 'label' => 'Payouts', 'route' => '/partner/payouts'],
        ];
    }

    /**
     * @return list<array{key: string, label: string, route: string}>
     */
    public function resellerNavigationItems(): array
    {
        return [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'route' => '/reseller/dashboard'],
            ['key' => 'quotes', 'label' => 'Quotes', 'route' => '/reseller/quotes'],
            ['key' => 'resources', 'label' => 'Resource center', 'route' => '/reseller/resources'],
            ['key' => 'commissions', 'label' => 'Commissions', 'route' => '/reseller/commissions'],
            ['key' => 'licenses', 'label' => 'License inventory', 'route' => '/reseller/licenses'],
            ['key' => 'payouts', 'label' => 'Payouts', 'route' => '/reseller/payouts'],
        ];
    }
}
