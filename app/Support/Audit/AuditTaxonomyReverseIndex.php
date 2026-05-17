<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Support\DomainConstants;

/**
 * Helps query legacy rows missing persisted event_key.
 *
 * @return list<array{0:string,1:string}> module/action pairs
 */
final class AuditTaxonomyReverseIndex
{
    /**
     * @return list<array{0:string,1:string}>
     */
    public static function moduleActionTuples(string $eventKey): array
    {
        return match ($eventKey) {
            BusinessAuditEventKeys::AUTH_LOGIN_SUCCESS => [['auth', 'login_success']],
            BusinessAuditEventKeys::AUTH_LOGIN_FAILURE => [['auth', 'login_failed']],
            BusinessAuditEventKeys::AUTH_EMAIL_VERIFIED => [['auth', 'email_verified']],
            BusinessAuditEventKeys::AUTH_PASSWORD_RESET_REQUESTED => [['auth', 'password_reset_requested']],
            BusinessAuditEventKeys::AUTH_PASSWORD_RESET_COMPLETED => [['auth', 'password_reset_completed']],
            BusinessAuditEventKeys::QUOTES_CREATED => [['quote', 'created']],
            BusinessAuditEventKeys::QUOTES_UPDATED => [['quote', 'updated']],
            BusinessAuditEventKeys::QUOTES_SENT => [['quote', 'sent']],
            BusinessAuditEventKeys::QUOTES_ACCEPTED => [['quote', 'public_accepted']],
            BusinessAuditEventKeys::QUOTES_REJECTED => [['quote', 'public_rejected']],
            BusinessAuditEventKeys::PAYMENTS_INITIATED => [['quote', 'payment_link_sent'], ['payments', 'initiated']],
            BusinessAuditEventKeys::PAYMENTS_WEBHOOK_SUCCESS => [['quote', 'payment_succeeded'], ['payments', 'webhook_success']],
            BusinessAuditEventKeys::PAYMENTS_WEBHOOK_FAILED => [['payments', 'webhook_gateway_failed'], ['payments', 'webhook_rejected']],
            BusinessAuditEventKeys::USERS_INVITED => [['user', 'invited']],
            BusinessAuditEventKeys::USERS_ACCESS_REVOKED => [['user', 'access_revoked']],
            BusinessAuditEventKeys::USERS_PROFILE_UPDATED => [['user', 'profile_updated']],
            BusinessAuditEventKeys::USERS_PASSWORD_CHANGED => [['user', 'password_changed']],
            BusinessAuditEventKeys::USERS_ROLE_CHANGED => [['user', DomainConstants::LOG_USER_ROLE_CHANGED]],
            BusinessAuditEventKeys::SMTP_UPDATED => [['organization_email_settings', 'email_settings.updated']],
            BusinessAuditEventKeys::SMTP_TESTED => [['organization_email_settings', 'email_settings.tested']],
            BusinessAuditEventKeys::LICENSES_ALLOCATED => [['prm', 'prm.license.allocated']],
            BusinessAuditEventKeys::LICENSES_TRANSFERRED => [['prm', 'prm.license.transferred']],
            BusinessAuditEventKeys::LICENSES_ACTIVATED => [['prm', 'prm.license.activated']],
            BusinessAuditEventKeys::CONTACTS_CREATED => [['contact', 'created']],
            BusinessAuditEventKeys::CONTACTS_UPDATED => [['contact', 'updated']],
            BusinessAuditEventKeys::CONTACTS_ASSIGNED => [['contact', 'assigned']],
            BusinessAuditEventKeys::CONTACTS_REASSIGNED => [['contact', 'reassigned']],
            BusinessAuditEventKeys::CONTACTS_DELETED => [['contact', 'deleted']],
            BusinessAuditEventKeys::CONTACTS_CONVERTED => [['contact', 'converted']],
            BusinessAuditEventKeys::COMPANIES_CREATED => [['company', 'created']],
            BusinessAuditEventKeys::COMPANIES_UPDATED => [['company', 'updated']],
            BusinessAuditEventKeys::COMPANIES_DELETED => [['company', 'deleted']],
            BusinessAuditEventKeys::PARTNERS_APPROVED => [['organization', DomainConstants::LOG_ORGANIZATION_APPROVED]],
            BusinessAuditEventKeys::PARTNERS_REJECTED => [['organization', DomainConstants::LOG_ORGANIZATION_REJECTED]],
            BusinessAuditEventKeys::PARTNERS_SUSPENDED => [['organization', DomainConstants::LOG_ORGANIZATION_SUSPENDED]],
            BusinessAuditEventKeys::RESELLERS_APPROVED => [['organization', DomainConstants::LOG_ORGANIZATION_APPROVED]],
            BusinessAuditEventKeys::RESELLERS_REJECTED => [['organization', DomainConstants::LOG_ORGANIZATION_REJECTED]],
            BusinessAuditEventKeys::RESELLERS_SUSPENDED => [['organization', DomainConstants::LOG_ORGANIZATION_SUSPENDED]],
            default => [],
        };
    }
}
