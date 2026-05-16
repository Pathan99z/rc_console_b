<?php

namespace App\Services\OrganizationMail;

use App\Models\OrganizationEmailSetting;
use App\Models\User;
use App\Support\OrganizationMail\OrganizationEmailAccessScope;
use App\Support\OrganizationMail\OrganizationMailContext;
use Illuminate\Support\Facades\Mail;
use Throwable;

class OrganizationEmailTestService
{
    public function __construct(
        private readonly OrganizationEmailAccessScope $accessScope,
        private readonly OrganizationMailResolverService $mailResolver,
        private readonly OrganizationEmailAuditLogger $auditLogger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function sendTest(User $actor, int $organizationId, string $recipientEmail, ?string $ip = null, ?string $ua = null): array
    {
        $this->accessScope->assertOrganizationEmailAccessible($actor, $organizationId);

        $resolved = $this->mailResolver->resolveForTenantOrganization((int) $actor->tenant_id, $organizationId);
        if ($resolved === null) {
            return [
                'success' => false,
                'message' => 'No SMTP configuration available for this organization (configure settings or parent hierarchy first).',
            ];
        }

        $tenantId = (int) $actor->tenant_id;

        try {
            OrganizationMailContext::run($tenantId, $organizationId, function () use ($recipientEmail, $organizationId): void {
                Mail::mailer(config('mail.default'))->raw(
                    'RC Console organization SMTP test email (organization '.$organizationId.').',
                    function ($message) use ($recipientEmail): void {
                        $message->to($recipientEmail)->subject('RC Console mail test');
                    }
                );
            });

            $row = OrganizationEmailSetting::query()
                ->where('tenant_id', $tenantId)
                ->where('organization_id', $organizationId)
                ->first();

            if ($row) {
                $row->update([
                    'last_tested_at' => now(),
                    'last_error' => null,
                    'failure_count' => 0,
                    'is_verified' => true,
                    'updated_by_user_id' => $actor->id,
                ]);
                $this->auditLogger->log($actor, 'email_settings.tested', $row->fresh(), null, ['recipient' => $recipientEmail, 'success' => true], $ip, $ua);
            }

            return [
                'success' => true,
                'message' => 'Test email sent successfully.',
                'effective_source_organization_id' => $resolved->sourceOrganizationId,
            ];
        } catch (Throwable $e) {
            report($e);

            $row = OrganizationEmailSetting::query()
                ->where('tenant_id', $tenantId)
                ->where('organization_id', $organizationId)
                ->first();

            if ($row) {
                $row->increment('failure_count');
                $row->update([
                    'last_tested_at' => now(),
                    'last_error' => $e->getMessage(),
                    'is_verified' => false,
                    'updated_by_user_id' => $actor->id,
                ]);
                $this->auditLogger->log($actor, 'email_settings.tested', $row->fresh(), null, [
                    'recipient' => $recipientEmail,
                    'success' => false,
                    'error' => $e->getMessage(),
                ], $ip, $ua);
            }

            return [
                'success' => false,
                'message' => 'Failed to send test email.',
                'error' => $e->getMessage(),
            ];
        }
    }
}
