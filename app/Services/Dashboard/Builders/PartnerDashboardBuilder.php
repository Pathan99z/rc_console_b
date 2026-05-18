<?php

namespace App\Services\Dashboard\Builders;

use App\Models\Role;
use App\Models\User;
use App\Services\Prm\PrmDashboardService;
use App\Support\Dashboard\DashboardScopeResolver;
use App\Support\Dashboard\LoginDashboardMetrics;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;

class PartnerDashboardBuilder implements LoginDashboardBuilderContract
{
    public function __construct(
        private readonly PrmDashboardService $prmDashboardService,
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly LoginDashboardMetrics $metrics,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(User $actor, array $filters = []): array
    {
        if (! $actor->isPartnerAdmin()) {
            throw new AuthorizationException('Partner admin dashboard access denied.');
        }

        $scope = $this->scopeResolver->resolveForActor($actor);
        [$from, $to] = $this->parsePeriod($filters);

        $portalPayload = $this->prmDashboardService->partnerDashboard($actor, $filters);
        $overview = $portalPayload['dashboard'] ?? [];
        $kpis = $overview['kpis'] ?? [];

        $enhancements = [
            'assigned_contacts' => $this->metrics->assignedContactsCount(
                $scope->tenantId,
                (int) $actor->id,
                $scope->organizationIds
            ),
            'task_summary' => $this->metrics->taskSummary(
                $scope->tenantId,
                (int) $actor->id,
                $scope->organizationIds
            ),
            'invitation_status' => $this->metrics->invitationStatus($scope->tenantId, $scope->organizationIds),
            'recent_notifications' => $this->metrics->recentNotifications($actor, 5),
        ];

        $flatKpis = $this->flattenPartnerKpis($kpis, $enhancements);

        return [
            'dashboard_profile' => Role::CODE_PARTNER_ADMIN,
            'tenant_id' => $scope->tenantId,
            'organization_id' => $scope->rootOrganizationId,
            'generated_at' => now()->toIso8601String(),
            'period' => $overview['period'] ?? [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'kpis' => $flatKpis,
            'widgets' => [
                'recent_notifications' => $enhancements['recent_notifications'],
            ],
            'enhancements' => $enhancements,
            'links' => [
                'partner_portal_dashboard' => '/api/prm/partner/dashboard',
                'organization_dashboard' => '/api/organizations/'.$scope->rootOrganizationId.'/dashboard',
                'notifications' => '/api/notifications',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $kpis
     * @param  array<string, mixed>  $enhancements
     * @return array<string, mixed>
     */
    private function flattenPartnerKpis(array $kpis, array $enhancements): array
    {
        return array_merge([
            'contacts' => (int) ($kpis['crm']['contacts'] ?? 0),
            'companies' => (int) ($kpis['crm']['companies'] ?? 0),
            'deals' => (int) ($kpis['crm']['deals'] ?? 0),
            'quotes' => (int) ($kpis['crm']['quotes'] ?? 0),
            'open_deals' => (int) ($kpis['deals']['open'] ?? 0),
            'won_deals' => (int) ($kpis['deals']['won'] ?? 0),
            'lost_deals' => (int) ($kpis['deals']['lost'] ?? 0),
            'pipeline_value' => (float) ($kpis['deals']['pipeline_value'] ?? 0),
            'total_revenue' => (float) ($kpis['revenue']['total_revenue'] ?? 0),
            'successful_payments' => (int) ($kpis['revenue']['successful_payments'] ?? 0),
            'failed_payments' => (int) ($kpis['revenue']['failed_payments'] ?? 0),
            'license_units_available' => (int) ($kpis['licenses']['available'] ?? 0),
            'partner_users_active' => (int) ($kpis['users']['active'] ?? 0),
        ], $enhancements);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function parsePeriod(array $filters): array
    {
        $from = ! empty($filters['from']) ? Carbon::parse((string) $filters['from'])->startOfDay() : null;
        $to = ! empty($filters['to']) ? Carbon::parse((string) $filters['to'])->endOfDay() : null;

        return [$from, $to];
    }
}
