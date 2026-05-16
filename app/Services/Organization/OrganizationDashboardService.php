<?php

namespace App\Services\Organization;

use App\Models\User;
use App\Support\Dashboard\DashboardMetricsRepository;
use App\Support\Dashboard\DashboardScope;
use App\Support\Dashboard\DashboardScopeResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class OrganizationDashboardService
{
    private const TTL_OVERVIEW = 600;

    private const TTL_PIPELINE = 600;

    private const TTL_REVENUE = 600;

    private const TTL_COMMISSIONS = 600;

    private const TTL_PAYOUTS = 600;

    private const TTL_LICENSES = 600;

    private const TTL_RESOURCES = 600;

    private const TTL_ACTIVITY = 120;

    private const TTL_TEAM = 300;

    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly DashboardMetricsRepository $metricsRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function overview(User $actor, int $organizationId, array $filters = []): array
    {
        return $this->remember($actor, $organizationId, 'overview', self::TTL_OVERVIEW, $filters, function (DashboardScope $scope, ?Carbon $from, ?Carbon $to): array {
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
        return $this->remember($actor, $organizationId, 'pipeline', self::TTL_PIPELINE, $filters, function (DashboardScope $scope, ?Carbon $from, ?Carbon $to): array {
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
        return $this->remember($actor, $organizationId, 'revenue', self::TTL_REVENUE, $filters, function (DashboardScope $scope, ?Carbon $from, ?Carbon $to): array {
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
        return $this->remember($actor, $organizationId, 'commissions', self::TTL_COMMISSIONS, $filters, function (DashboardScope $scope, ?Carbon $from, ?Carbon $to): array {
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
        return $this->remember($actor, $organizationId, 'payouts', self::TTL_PAYOUTS, $filters, function (DashboardScope $scope, ?Carbon $from, ?Carbon $to): array {
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
        return $this->remember($actor, $organizationId, 'licenses', self::TTL_LICENSES, $filters, function (DashboardScope $scope, ?Carbon $from, ?Carbon $to): array {
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
        return $this->remember($actor, $organizationId, 'activity', self::TTL_ACTIVITY, $filters, function (DashboardScope $scope, ?Carbon $from, ?Carbon $to): array {
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
        return $this->remember($actor, $organizationId, 'team', self::TTL_TEAM, $filters, function (DashboardScope $scope, ?Carbon $from, ?Carbon $to): array {
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
        return $this->remember($actor, $organizationId, 'resources', self::TTL_RESOURCES, $filters, function (DashboardScope $scope, ?Carbon $from, ?Carbon $to): array {
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
        $pattern = "dashboard:{$tenantId}:{$organizationId}:*";
        if (config('cache.default') === 'array') {
            Cache::flush();

            return;
        }
        // Tagless flush: bump version key for org dashboards.
        Cache::put("dashboard:{$tenantId}:{$organizationId}:version", (string) microtime(true), 86400);
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
        $version = Cache::get("dashboard:{$scope->tenantId}:{$organizationId}:version", '1');
        $key = sprintf(
            'dashboard:%d:%d:%s:%s:%s:v1',
            $scope->tenantId,
            $organizationId,
            $section,
            $from?->toDateString() ?? 'all',
            $to?->toDateString() ?? 'all',
        ).':'.$version;

        return Cache::remember($key, $ttl, fn () => $builder($scope, $from, $to));
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
