<?php

declare(strict_types=1);

namespace App\Support\Cache;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Caches permission / navigation bootstrap payloads per user (no PII in keys).
 */
final class UserBootstrapCache
{
    /**
     * @return array<string, mixed>
     */
    public function rememberPermissions(User $user, Closure $callback): array
    {
        $key = $this->key($user, 'permissions');
        $ttl = (int) config('enterprise_cache.ttl.user_bootstrap', 300);

        /** @var array<string, mixed> */
        return Cache::remember($key, $ttl, $callback);
    }

    public function invalidate(User $user): void
    {
        Cache::put(
            $this->versionKey((int) $user->id),
            (string) microtime(true),
            now()->addDays((int) config('enterprise_cache.version_ttl_days', 30))
        );
    }

    private function key(User $user, string $segment): string
    {
        $version = (string) Cache::get($this->versionKey((int) $user->id), '1');
        $tenantId = $user->tenant_id !== null ? (int) $user->tenant_id : 0;

        return "bootstrap:{$segment}:u:{$user->id}:t:{$tenantId}:r:{$user->currentRoleCode()}:v:{$version}";
    }

    private function versionKey(int $userId): string
    {
        return "bootstrap:u:{$userId}:ver";
    }
}
