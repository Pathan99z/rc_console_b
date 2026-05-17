<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\Organization;
use App\Support\DomainConstants;

/**
 * Resolves enterprise event_key for legacy rows where event_key was not supplied at insert time.
 */
final class AuditLogTaxonomyResolver
{
    public static function resolve(
        string $module,
        string $action,
        string $entityType,
        ?array $before,
        ?array $after
    ): ?string {
        $snapshot = $after ?? $before ?? [];

        $orgType = null;
        if (($snapshot['type'] ?? null) !== null && is_string($snapshot['type'])) {
            $orgType = $snapshot['type'];
        }

        if ($module === 'organization' || str_starts_with((string) $action, 'organization.')) {
            return self::organizationEvent($action, $orgType);
        }

        if ($module === 'quote') {
            return match ($action) {
                'created' => BusinessAuditEventKeys::QUOTES_CREATED,
                'updated' => BusinessAuditEventKeys::QUOTES_UPDATED,
                'status_changed' => BusinessAuditEventKeys::QUOTES_UPDATED,
                'deleted' => BusinessAuditEventKeys::QUOTES_UPDATED,
                'sent' => BusinessAuditEventKeys::QUOTES_SENT,
                'public_accepted' => BusinessAuditEventKeys::QUOTES_ACCEPTED,
                'public_rejected' => BusinessAuditEventKeys::QUOTES_REJECTED,
                'payment_link_sent' => BusinessAuditEventKeys::PAYMENTS_INITIATED,
                'payment_succeeded' => BusinessAuditEventKeys::PAYMENTS_WEBHOOK_SUCCESS,
                'public_viewed', 'attachment_uploaded' => null,
                default => null,
            };
        }

        if ($module === 'payments') {
            return match ($action) {
                'initiated' => BusinessAuditEventKeys::PAYMENTS_INITIATED,
                'webhook_success' => BusinessAuditEventKeys::PAYMENTS_WEBHOOK_SUCCESS,
                'webhook_gateway_failed', 'webhook_rejected' => BusinessAuditEventKeys::PAYMENTS_WEBHOOK_FAILED,
                default => null,
            };
        }

        if ($module === 'auth') {
            return match ($action) {
                'login_success' => BusinessAuditEventKeys::AUTH_LOGIN_SUCCESS,
                'login_failed' => BusinessAuditEventKeys::AUTH_LOGIN_FAILURE,
                'email_verified' => BusinessAuditEventKeys::AUTH_EMAIL_VERIFIED,
                'password_reset_requested' => BusinessAuditEventKeys::AUTH_PASSWORD_RESET_REQUESTED,
                'password_reset_completed' => BusinessAuditEventKeys::AUTH_PASSWORD_RESET_COMPLETED,
                default => null,
            };
        }

        if ($module === 'organization_email_settings' || str_starts_with((string) $action, 'email_settings.')) {
            return match ($action) {
                'email_settings.updated' => BusinessAuditEventKeys::SMTP_UPDATED,
                'email_settings.tested' => BusinessAuditEventKeys::SMTP_TESTED,
                default => BusinessAuditEventKeys::SMTP_UPDATED,
            };
        }

        if ($module === 'user') {
            return match ($action) {
                'invited' => BusinessAuditEventKeys::USERS_INVITED,
                'access_revoked' => BusinessAuditEventKeys::USERS_ACCESS_REVOKED,
                'profile_updated' => BusinessAuditEventKeys::USERS_PROFILE_UPDATED,
                'password_changed' => BusinessAuditEventKeys::USERS_PASSWORD_CHANGED,
                DomainConstants::LOG_USER_ROLE_CHANGED => BusinessAuditEventKeys::USERS_ROLE_CHANGED,
                default => null,
            };
        }

        if ($module === 'prm') {
            if ($action === 'prm.license.allocated') {
                return BusinessAuditEventKeys::LICENSES_ALLOCATED;
            }
            if ($action === 'prm.license.transferred') {
                return BusinessAuditEventKeys::LICENSES_TRANSFERRED;
            }
            if ($action === 'prm.license.activated') {
                return BusinessAuditEventKeys::LICENSES_ACTIVATED;
            }
        }

        if ($module === 'contact') {
            return match ($action) {
                'created' => BusinessAuditEventKeys::CONTACTS_CREATED,
                'updated' => BusinessAuditEventKeys::CONTACTS_UPDATED,
                'assigned' => BusinessAuditEventKeys::CONTACTS_ASSIGNED,
                'reassigned' => BusinessAuditEventKeys::CONTACTS_REASSIGNED,
                'deleted' => BusinessAuditEventKeys::CONTACTS_DELETED,
                'converted' => BusinessAuditEventKeys::CONTACTS_CONVERTED,
                default => null,
            };
        }

        if ($module === 'company') {
            return match ($action) {
                'created' => BusinessAuditEventKeys::COMPANIES_CREATED,
                'updated' => BusinessAuditEventKeys::COMPANIES_UPDATED,
                'deleted' => BusinessAuditEventKeys::COMPANIES_DELETED,
                default => null,
            };
        }

        return null;
    }

    private static function organizationEvent(string $action, ?string $orgType): ?string
    {
        $isPartner = $orgType === Organization::TYPE_PARTNER;
        $isReseller = $orgType === Organization::TYPE_RESELLER;

        return match ($action) {
            DomainConstants::LOG_ORGANIZATION_CREATED => $isPartner
                ? BusinessAuditEventKeys::PARTNERS_CREATED
                : ($isReseller ? BusinessAuditEventKeys::RESELLERS_CREATED : null),
            DomainConstants::LOG_ORGANIZATION_APPROVED => $isPartner
                ? BusinessAuditEventKeys::PARTNERS_APPROVED
                : ($isReseller ? BusinessAuditEventKeys::RESELLERS_APPROVED : null),
            DomainConstants::LOG_ORGANIZATION_REJECTED => $isPartner
                ? BusinessAuditEventKeys::PARTNERS_REJECTED
                : ($isReseller ? BusinessAuditEventKeys::RESELLERS_REJECTED : null),
            DomainConstants::LOG_ORGANIZATION_SUSPENDED => $isPartner
                ? BusinessAuditEventKeys::PARTNERS_SUSPENDED
                : ($isReseller ? BusinessAuditEventKeys::RESELLERS_SUSPENDED : null),
            default => null,
        };
    }
}
