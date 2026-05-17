<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AuditRetentionService
{
    public function archiveOlderThanSearchableWindow(): int
    {
        /** @var int $days */
        $days = (int) config('audit.archive_when_older_than_days', 365);
        $threshold = Carbon::now()->subDays($days);

        return (int) DB::table('audit_logs')
            ->where('created_at', '<', $threshold)
            ->whereNull('archived_at')
            ->update(['archived_at' => Carbon::now()]);
    }

    /**
     * Hard-delete rows beyond purge policy (immutable store physical delete).
     */
    public function purgeBeyondRetention(): int
    {
        /** @var int $days */
        $days = (int) config('audit.purge_when_older_than_days', 2555);
        $threshold = Carbon::now()->subDays($days);

        return (int) DB::table('audit_logs')->where('created_at', '<', $threshold)->delete();
    }
}
