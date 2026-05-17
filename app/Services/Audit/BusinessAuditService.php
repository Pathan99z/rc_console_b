<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Repositories\AuditLogRepository;
use Illuminate\Http\Request;

/**
 * Explicit enterprise audit rows (auth, payments, CRM gaps). Legacy writers may continue using AuditLogRepository;
 * model events apply redaction, correlation, default event_key resolution, and immutability timestamps.
 */
final class BusinessAuditService
{
    public function __construct(private readonly AuditLogRepository $auditLogRepository) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        string $eventKey,
        ?int $tenantId,
        ?int $actorUserId,
        string $module,
        string $action,
        string $entityType,
        int $entityId,
        ?array $before = null,
        ?array $after = null,
        ?array $metadata = null,
        ?int $organizationId = null,
        ?string $source = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?Request $request = null,
    ): AuditLog {
        $req = $request ?? (app()->bound('request') ? request() : null);
        if ($ip === null && $req) {
            $ip = $req->ip();
        }
        if ($userAgent === null && $req) {
            $userAgent = $req->userAgent();
        }

        $correlation = null;
        if ($req) {
            $c = $req->attributes->get('correlation_id');
            $correlation = is_string($c) && $c !== '' ? $c : null;
        }

        return $this->auditLogRepository->create([
            'tenant_id' => $tenantId,
            'organization_id' => $organizationId,
            'correlation_id' => $correlation,
            'user_id' => $actorUserId,
            'module' => $module,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'metadata' => $metadata,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'event_key' => $eventKey,
            'source' => $source ?? ($req ? 'http' : 'system'),
            // immutable_at filled in model creating hook if null
        ]);
    }
}
