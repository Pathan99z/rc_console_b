<?php

namespace App\Services\DemoLinks;

use App\Models\DemoLink;
use App\Models\User;
use App\Repositories\AuditLogRepository;

class DemoLinkAuditLogger
{
    public function __construct(private readonly AuditLogRepository $auditLogRepository) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        User $actor,
        string $action,
        DemoLink $link,
        ?array $before = null,
        ?array $after = null,
        ?string $ip = null,
        ?string $ua = null
    ): void {
        $this->auditLogRepository->create([
            'tenant_id' => $link->tenant_id,
            'user_id' => $actor->id,
            'module' => 'demo_links',
            'action' => $action,
            'entity_type' => 'demo_link',
            'entity_id' => $link->id,
            'before' => $before,
            'after' => $after ?? $link->toArray(),
            'ip_address' => $ip,
            'user_agent' => $ua,
        ]);
    }
}
