<?php

namespace App\Services\Organization;

use App\Models\User;
use App\Support\Cache\DashboardCacheManager;
use App\Support\Dashboard\DashboardMetricsRepository;
use App\Support\Dashboard\DashboardScope;
use App\Support\Dashboard\DashboardScopeResolver;
use Carbon\Carbon;

class OrganizationDashboardService
{
    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly DashboardMetricsRepository $metricsRepository,
        private readonly DashboardCacheManager $dashboardCache,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function overview(User $actor, int $organizationId, array $filters = []): array
    {
        return $this->remember($actor, $organizationId, 'overview', (int) config('enterprise_cache.ttl.org_dashboard_overview', 600), $filters, function (DashboardScope $scope, ?Carbon $from, ?Carbon $to): array {
            return $this->wrapPayload($scope, $from, $to, [
                'kpis' => $this->metricsRepository->overview($scope, $from, $to),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function pipeline(User $actor, int $organizationId, array $filters = []): array
    {
        return $this->remember($actor, $organizationId, 'pipeline', (int) config('enterprise_cache.ttl.org_dashboard_pipeline', 600), $filters, function (DashboardScope $scope, ?Carbon $from, ?Carbon $to): array {
            return $this->wrapPayload($scope, $from, $to, [
                'pipeline' => $this->metricsRepository->pipeline($scope, $from, $to),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function revenue(User $actor, int $organizationId, array $filters = []): array
    {
        return $this->remember($actor, $organizationId, 'revenue', (int) config('enterprise_cache.ttl.org_dashboard_revenue', 600), $filters, function (DashboardScope $scope, ?Carbon $from, ?Carbon $to): array {
            return $this->wrapPayload($scope, $from, $to, [
                'revenue' => $this->metricsRepository->revenue($scope, $from, $to),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function commissions(User $actor, int $organizationId, array $filters = []): array
    {
        return $this->remember($actor, $organizationId, 'commissions', (int) config('enterprise_cache.ttl.org_dashboard_commissions', 600), $filters, function (DashboardScope $scope, ?Carbon $from, ?Carbon $to): array {
            return $this->wrapPayload($scope, $from, $to, [
                'commissions' => $this->metricsRepository->commissions($scope, $from, $to),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function payouts(User $actor, int $organizationId, array $filters = []): array
    {
        return $this->remember($actor, $organizationId, 'payouts', (int) config('enterprise_cache.ttl.org_dashboard_payouts', 600), $filters, function (DashboardScope $scope, ?Carbon $from, ?Carbon $to): array
        {
            return $this->wrapPayload($scope, $from, $to, [
                'payouts' => $this->metricsRepository->payoutsSection($scope, $from, $to),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function licenses(User $actor, int $organizationId, array $filters = []): array
    {
        return $this->remember($actor, $organizationId, 'licenses', (int) config('enterprise_cache.ttl.org_dashboard_licenses', 600), $filters, function (DashboardScope $scope, ?Carbon $from, ?Carbon $to): array {
            return $this->wrapPayload($scope, $from, $to, [
                'licenses' => $this->metricsRepository->licenses($scope, $from, $to),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function activity(User $actor, int $organizationId, array $filters = []): array
    {
        return $this->remember($actor, $organizationId, 'activity', (int) config('enterprise_cache.ttl.org_dashboard_activity', 120), $filters, function (DashboardScope $scope, ?Carbon $from, ?Carbon $to): array {
            return $this->wrapPayload($scope, $from, $to, [
                'activity' => $this->metricsRepository->activity($scope, $from, $to),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function team(User $actor, int $organizationId, array $filters = []): array
    {
        return $this->remember($actor, $organizationId, 'team', (int) config('enterprise_cache.ttl.org_dashboard_team', 300), $filters, function (DashboardScope $scope, ?Carbon $from, ?Carbon $to): array {
            return $this->wrapPayload($scope, $from, $to, [
                'team' => $this->metricsRepository->team($scope, $from, $to),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function resources(User $actor, int $organizationId, array $filters = []): array
    {
        return $this->remember($actor, $organizationId, 'resources', (int) config('enterprise_cache.ttl.org_dashboard_resources', 600), $filters, function (DashboardScope $scope, ?Carbon $from, ?Carbon $to): array {
            return $this->wrapPayload($scope, $from, $to, [
                'resources' => $this->metricsRepository->resources($scope, $from, $to),
            ]);
        });
    }

    /**
     * Self-service dashboard for partner/reseller portal (actor primary org).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function overviewForActor(User $actor, array $filters = []): array
    {
        $scope = $this->scopeResolver->resolveForActor($actor);

        return $this->overview($actor, $scope->rootOrganizationId, $filters);
    }

    public static function flushCache(int $tenantId, int $organizationId): void
    {
        $manager = app(DashboardCacheManager::class);
        $manager->invalidateOrganization($tenantId, $organizationId);
        $manager->invalidateTenantEpoch($tenantId);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  callable(DashboardScope, ?Carbon, ?Carbon): array<string, mixed>  $builder
     * @return array<string, mixed>
     */
    private function remember(User $actor, int $organizationId, string $section, int $ttl, array $filters, callable $builder): array
    {
        $scope = $this->scopeResolver->resolve($actor, $organizationId);
        [$from, $to] = $this->parsePeriod($filters);
        $versions = $this->dashboardCache->organizationVersions($scope->tenantId, $organizationId);
        $key = sprintf(
            'dashboard:%d:%d:%s:%s:%s:v2:%s',
            $scope->tenantId,
            $organizationId,
            $section,
            $from?->toDateString() ?? 'all',
            $to?->toDateString() ?? 'all',
            $versions,
        );

        return $this->dashboardCache->remember($key, $ttl, fn () => $builder($scope, $from, $to));
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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function wrapPayload(DashboardScope $scope, ?Carbon $from, ?Carbon $to, array $payload): array
    {
        $org = $scope->organization;

        return array_merge([
            'organization' => [
                'id' => $org->id,
                'type' => $org->type,
                'channel_mode' => $org->channel_mode,
                'display_name' => $org->display_name,
                'legal_name' => $org->legal_name,
                'parent_organization_id' => $org->parent_organization_id,
                'onboarding_status' => $org->onboarding_status,
                'status' => $org->status,
            ],
            'scope_organization_ids' => $scope->organizationIds,
            'includes_children' => $scope->includesChildren,
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'generated_at' => now()->toIso8601String(),
        ], $payload);
    }
}
