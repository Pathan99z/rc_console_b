<?php

declare(strict_types=1);

namespace App\Support\Cache;

use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Versioned dashboard cache with stampede protection (no global flush).
 */
final class DashboardCacheManager
{
    public function remember(string $key, int $ttlSeconds, Closure $callback): mixed
    {
        $lockKey = 'lock:'.$key;

        return Cache::remember($key, $ttlSeconds, function () use ($lockKey, $callback) {
            $lock = Cache::lock($lockKey, (int) config('enterprise_cache.stampede_lock_seconds', 10));

            return $lock->block(5, $callback);
        });
    }

    public function organizationVersions(int $tenantId, int $organizationId): string
    {
        $tenantEpoch = (string) Cache::get($this->tenantEpochKey($tenantId), '1');
        $orgVersion = (string) Cache::get($this->organizationVersionKey($tenantId, $organizationId), '1');

        return $tenantEpoch.':'.$orgVersion;
    }

    public function invalidateOrganization(int $tenantId, int $organizationId): void
    {
        Cache::put(
            $this->organizationVersionKey($tenantId, $organizationId),
            (string) microtime(true),
            now()->addDays((int) config('enterprise_cache.version_ttl_days', 30))
        );
    }

    public function invalidateTenantEpoch(int $tenantId): void
    {
        Cache::put(
            $this->tenantEpochKey($tenantId),
            (string) microtime(true),
            now()->addDays((int) config('enterprise_cache.version_ttl_days', 30))
        );
    }

    public function loginDashboardVersion(User $actor): string
    {
        if ($actor->isGlobalAdmin()) {
            return (string) Cache::get($this->loginGlobalVersionKey(), '1');
        }

        $tenantId = (int) $actor->tenant_id;
        $tenantVer = (string) Cache::get($this->loginTenantVersionKey($tenantId), '1');

        if ($actor->currentRoleCode() === Role::CODE_COMPANY_ADMIN) {
            return $tenantVer.':'.(string) Cache::get($this->loginCompanyVersionKey($tenantId), '1');
        }

        if (in_array($actor->currentRoleCode(), [Role::CODE_PARTNER_ADMIN, Role::CODE_RESELLER_ADMIN], true)) {
            $userVer = (string) Cache::get($this->loginUserVersionKey($tenantId, (int) $actor->id), '1');

            return $tenantVer.':'.$userVer;
        }

        return $tenantVer;
    }

    public function invalidateLoginGlobal(): void
    {
        Cache::put($this->loginGlobalVersionKey(), (string) microtime(true), now()->addDays(30));
    }

    public function invalidateLoginTenant(int $tenantId): void
    {
        Cache::put($this->loginTenantVersionKey($tenantId), (string) microtime(true), now()->addDays(30));
        Cache::put($this->loginCompanyVersionKey($tenantId), (string) microtime(true), now()->addDays(30));
    }

    public function invalidateLoginUser(int $tenantId, int $userId): void
    {
        Cache::put($this->loginUserVersionKey($tenantId, $userId), (string) microtime(true), now()->addDays(30));
    }

    private function tenantEpochKey(int $tenantId): string
    {
        return "dashboard:t:{$tenantId}:epoch";
    }

    private function organizationVersionKey(int $tenantId, int $organizationId): string
    {
        return "dashboard:t:{$tenantId}:o:{$organizationId}:ver";
    }

    private function loginGlobalVersionKey(): string
    {
        return 'login-dashboard:ver:global';
    }

    private function loginTenantVersionKey(int $tenantId): string
    {
        return "login-dashboard:ver:t:{$tenantId}";
    }

    private function loginCompanyVersionKey(int $tenantId): string
    {
        return "login-dashboard:ver:t:{$tenantId}:company";
    }

    private function loginUserVersionKey(int $tenantId, int $userId): string
    {
        return "login-dashboard:ver:t:{$tenantId}:u:{$userId}";
    }
}
