<?php

namespace App\Services\Prm;

use App\Models\CommissionAccrual;
use App\Models\Deal;
use App\Models\LicenseEntitlement;
use App\Models\PartnerLead;
use App\Models\Quote;
use App\Support\PartnerScopeResolver;

class PrmDashboardService
{
    public function __construct(private readonly PartnerScopeResolver $partnerScopeResolver) {}

    /**
     * @return array<string, mixed>
     */
    public function partnerSummary(User $actor): array
    {
        $tenantId = (int) $actor->tenant_id;
        $orgId = (int) ($actor->primaryOrganizationId() ?? 0);
        $orgIds = $this->partnerScopeResolver->visibleChannelOrganizationIds($actor);

        $leads = PartnerLead::query()->where('tenant_id', $tenantId)->whereIn('partner_organization_id', $orgIds)->count();
        $deals = Deal::query()->where('tenant_id', $tenantId)->whereIn('partner_organization_id', $orgIds)->count();
        $quotes = Quote::query()->where('tenant_id', $tenantId)->whereHas('deal', fn ($q) => $q->whereIn('partner_organization_id', $orgIds))->count();
        $commissionPending = CommissionAccrual::query()
            ->where('tenant_id', $tenantId)
            ->where('partner_organization_id', $orgId)
            ->where('status', CommissionAccrual::STATUS_PENDING)
            ->sum('commission_amount');
        $licenses = LicenseEntitlement::query()
            ->where('tenant_id', $tenantId)
            ->where('holder_organization_id', $orgId)
            ->selectRaw('coalesce(sum(units_total - units_consumed),0) as stock')
            ->value('stock');

        return [
            'partner_organization_id' => $orgId,
            'counts' => [
                'leads' => $leads,
                'deals' => $deals,
                'quotes' => $quotes,
            ],
            'commission_pending_total' => (float) $commissionPending,
            'license_units_available' => (int) $licenses,
            'pipeline_value' => (float) Deal::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('partner_organization_id', $orgIds)
                ->sum('estimated_value'),
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
        ];
    }
}
