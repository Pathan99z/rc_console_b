<?php

declare(strict_types=1);

namespace App\Support\Audit;

final class BusinessAuditEventKeys
{
    public const AUTH_LOGIN_SUCCESS = 'auth.login_success';

    public const AUTH_LOGIN_FAILURE = 'auth.login_failure';

    public const AUTH_PASSWORD_RESET_REQUESTED = 'auth.password_reset_requested';

    public const AUTH_PASSWORD_RESET_COMPLETED = 'auth.password_reset_completed';

    public const AUTH_EMAIL_VERIFIED = 'auth.email_verified';

    public const USERS_INVITED = 'users.invited';

    public const USERS_ROLE_CHANGED = 'users.role_changed';

    public const USERS_ACCESS_REVOKED = 'users.access_revoked';

    public const USERS_PROFILE_UPDATED = 'users.profile_updated';

    public const USERS_PASSWORD_CHANGED = 'users.password_changed';

    public const CONTACTS_CREATED = 'contacts.created';

    public const CONTACTS_UPDATED = 'contacts.updated';

    public const CONTACTS_ASSIGNED = 'contacts.assigned';

    public const CONTACTS_REASSIGNED = 'contacts.reassigned';

    public const CONTACTS_DELETED = 'contacts.deleted';

    public const CONTACTS_CONVERTED = 'contacts.converted';

    public const COMPANIES_CREATED = 'companies.created';

    public const COMPANIES_UPDATED = 'companies.updated';

    public const COMPANIES_DELETED = 'companies.deleted';

    public const DEALS_CREATED = 'deals.created';

    public const DEALS_OWNER_CHANGED = 'deals.owner_changed';

    public const DEALS_STAGE_CHANGED = 'deals.stage_changed';

    public const DEALS_WON = 'deals.won';

    public const DEALS_LOST = 'deals.lost';

    public const QUOTES_CREATED = 'quotes.created';

    public const QUOTES_UPDATED = 'quotes.updated';

    public const QUOTES_SENT = 'quotes.sent';

    public const QUOTES_ACCEPTED = 'quotes.accepted';

    public const QUOTES_REJECTED = 'quotes.rejected';

    public const PAYMENTS_INITIATED = 'payments.initiated';

    public const PAYMENTS_WEBHOOK_SUCCESS = 'payments.webhook_success';

    public const PAYMENTS_WEBHOOK_FAILED = 'payments.webhook_failed';

    public const LICENSES_ALLOCATED = 'licenses.allocated';

    public const LICENSES_TRANSFERRED = 'licenses.transferred';

    public const LICENSES_ACTIVATED = 'licenses.activated';

    public const PARTNERS_CREATED = 'partners.created';

    public const PARTNERS_APPROVED = 'partners.approved';

    public const PARTNERS_REJECTED = 'partners.rejected';

    public const PARTNERS_SUSPENDED = 'partners.suspended';

    public const RESELLERS_CREATED = 'resellers.created';

    public const RESELLERS_APPROVED = 'resellers.approved';

    public const RESELLERS_REJECTED = 'resellers.rejected';

    public const RESELLERS_SUSPENDED = 'resellers.suspended';

    public const SMTP_UPDATED = 'smtp.updated';

    public const SMTP_TESTED = 'smtp.tested';
}
