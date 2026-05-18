<?php

declare(strict_types=1);

namespace App\Support\Cache;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;

final class NotificationCountCache
{
    public function remember(User $user, Closure $callback): int
    {
        $version = (string) Cache::get($this->versionKey((int) $user->id), '1');
        $key = 'notify:unread:u:'.(int) $user->id.':t:'.(int) $user->tenant_id.':v:'.$version;
        $ttl = (int) config('enterprise_cache.ttl.notification_unread', 30);

        return (int) Cache::remember($key, $ttl, $callback);
    }

    public function invalidate(User $user): void
    {
        Cache::put(
            $this->versionKey((int) $user->id),
            (string) microtime(true),
            now()->addDays((int) config('enterprise_cache.version_ttl_days', 30))
        );
    }

    private function versionKey(int $userId): string
    {
        return "notify:unread:u:{$userId}:ver";
    }
}
