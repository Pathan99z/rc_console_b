<?php

namespace App\Services\Dashboard\Builders;

use App\Models\Role;
use App\Models\User;
use App\Services\Auth\PermissionResolverService;
use App\Support\Dashboard\LoginDashboardMetrics;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;

class GlobalDashboardBuilder implements LoginDashboardBuilderContract
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
        if (! $actor->isGlobalAdmin()) {
            throw new AuthorizationException('Global admin dashboard access denied.');
        }

        [$from, $to] = $this->parsePeriod($filters);
        $kpis = $this->metrics->platformKpis($from, $to);

        $widgets = [];
        if ($this->permissionResolver->can($actor, 'audit.view')) {
            $widgets['recent_audit_events'] = $this->metrics->recentAuditEvents(null, 15);
        }

        return $this->wrapPayload(Role::CODE_GLOBAL_ADMIN, $from, $to, $kpis, $widgets);
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

    /**
     * @param  array<string, mixed>  $kpis
     * @param  array<string, mixed>  $widgets
     * @return array<string, mixed>
     */
    private function wrapPayload(string $profile, ?Carbon $from, ?Carbon $to, array $kpis, array $widgets): array
    {
        return [
            'dashboard_profile' => $profile,
            'generated_at' => now()->toIso8601String(),
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'kpis' => $kpis,
            'widgets' => $widgets,
            'links' => [
                'tenants' => '/api/tenants',
                'audit_logs' => '/api/audit-logs',
            ],
        ];
    }
}
