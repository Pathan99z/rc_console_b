<?php

namespace App\Services\Prm;

use App\Models\Payout;
use App\Models\User;
use App\Repositories\AuditLogRepository;

class PayoutAuditLogger
{
    public function __construct(private readonly AuditLogRepository $auditLogRepository) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        User $actor,
        string $action,
        Payout $payout,
        ?array $before = null,
        ?array $after = null,
        ?string $ip = null,
        ?string $ua = null,
    ): void {
        $this->auditLogRepository->create([
            'tenant_id' => $payout->tenant_id,
            'user_id' => $actor->id,
            'module' => 'prm.payout',
            'action' => $action,
            'entity_type' => 'payout',
            'entity_id' => $payout->id,
            'before' => $before,
            'after' => $after ?? $payout->fresh()?->toArray(),
            'ip_address' => $ip,
            'user_agent' => $ua,
        ]);
    }
}
