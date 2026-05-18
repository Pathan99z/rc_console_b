<?php

declare(strict_types=1);

namespace App\Support\Cache;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/**
 * Tenant- and actor-scoped list pagination cache with version-based invalidation.
 */
final class TenantListCache
{
    public function __construct(private readonly ActorCacheScope $actorCacheScope) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function remember(
        User $actor,
        string $module,
        ?int $tenantId,
        array $filters,
        int $perPage,
        int $ttlSeconds,
        Closure $callback,
    ): LengthAwarePaginator {
        $key = $this->buildKey($actor, $module, $tenantId, $filters, $perPage);

        return Cache::remember($key, $ttlSeconds, $callback);
    }

    public function bumpVersion(string $module, ?int $tenantId): void
    {
        $key = $this->versionKey($module, $tenantId);
        Cache::add($key, 1, now()->addDays((int) config('enterprise_cache.version_ttl_days', 30)));
        Cache::increment($key);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function buildKey(User $actor, string $module, ?int $tenantId, array $filters, int $perPage): string
    {
        $tenantSegment = $this->tenantSegment($actor, $tenantId);
        $version = Cache::get($this->versionKey($module, $tenantId), 1);
        $scope = $this->actorCacheScope->fingerprint($actor);
        $filterHash = hash('sha256', json_encode($this->normalizeFilters($filters)));

        return "list:{$module}:t:{$tenantSegment}:v:{$version}:a:{$scope}:p:{$perPage}:f:{$filterHash}";
    }

    public function versionKey(string $module, ?int $tenantId): string
    {
        return 'list:'.$module.':t:'.$this->tenantSegmentForVersion($tenantId).':ver';
    }

    private function tenantSegment(User $actor, ?int $tenantId): string
    {
        if ($actor->isGlobalAdmin()) {
            return $tenantId !== null ? (string) $tenantId : 'all';
        }

        return (string) ($tenantId ?? $actor->tenant_id);
    }

    private function tenantSegmentForVersion(?int $tenantId): string
    {
        return $tenantId !== null ? (string) $tenantId : 'platform';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        ksort($filters);

        return $filters;
    }
}
