<?php

namespace App\Services\OrganizationMail;

use App\Models\OrganizationEmailSetting;
use App\Models\User;
use App\Repositories\AuditLogRepository;

class OrganizationEmailAuditLogger
{
    public function __construct(private readonly AuditLogRepository $auditLogRepository) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(User $actor, string $action, OrganizationEmailSetting $row, ?array $before = null, ?array $after = null, ?string $ip = null, ?string $ua = null): void
    {
        $sanitize = static function (?array $payload): ?array {
            if ($payload === null) {
                return null;
            }
            unset($payload['encrypted_password']);

            return $payload;
        };

        $this->auditLogRepository->create([
            'tenant_id' => $row->tenant_id,
            'user_id' => $actor->id,
            'module' => 'organization_email_settings',
            'action' => $action,
            'entity_type' => 'organization_email_setting',
            'entity_id' => $row->id,
            'before' => $sanitize($before),
            'after' => $sanitize($after ?? $row->toArray()),
            'ip_address' => $ip,
            'user_agent' => $ua,
        ]);
    }
}
