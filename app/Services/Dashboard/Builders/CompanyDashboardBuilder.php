<?php

namespace App\Services\Dashboard\Builders;

use App\Models\Role;
use App\Models\User;
use App\Services\Auth\PermissionResolverService;
use App\Support\Dashboard\LoginDashboardMetrics;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;

class CompanyDashboardBuilder implements LoginDashboardBuilderContract
{
    public function __construct(
        private readonly LoginDashboardMetrics $metrics,
        private readonly PermissionResolverService $permissionResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(User $actor, array $filters = []): array
    {
        if (! $actor->isCompanyAdmin() || $actor->tenant_id === null) {
            throw new AuthorizationException('Company admin dashboard access denied.');
        }

        $tenantId = (int) $actor->tenant_id;
        [$from, $to] = $this->parsePeriod($filters);
        $kpis = $this->metrics->tenantKpis($tenantId, $from, $to);

        $widgets = [
            'recent_crm_activity' => $this->metrics->recentCrmActivityForTenant($tenantId, 10),
        ];

        if ($this->permissionResolver->can($actor, 'audit.view')) {
            $widgets['recent_audit_activity'] = $this->metrics->recentAuditEvents($tenantId, 10);
        }

        return [
            'dashboard_profile' => Role::CODE_COMPANY_ADMIN,
            'tenant_id' => $tenantId,
            'generated_at' => now()->toIso8601String(),
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'kpis' => $kpis,
            'widgets' => $widgets,
            'links' => [
                'contacts' => '/api/contacts',
                'deals' => '/api/deals',
                'quotes' => '/api/quotes',
                'tasks' => '/api/tasks',
                'audit_logs' => '/api/audit-logs',
            ],
        ];
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
