<?php

namespace App\Support\Access;

use App\Models\Role;
use App\Models\User;

class PermissionProfileResolver
{
    /**
     * @return list<string>
     */
    public function roles(User $user): array
    {
        return [$user->currentRoleCode()];
    }

    /**
     * @return list<string>
     */
    public function permissions(User $user): array
    {
        $role = $user->currentRoleCode();

        $payoutFinance = [
            'prm.payouts.view',
            'prm.payouts.manage',
            'prm.payouts.approve',
            'prm.payouts.process',
            'prm.payouts.export',
            'prm.payout.accounts.manage',
            'prm.payout.disputes.manage',
        ];

        $tasksFull = [
            'tasks.view',
            'tasks.manage',
            'tasks.assign',
            'tasks.manage_all',
        ];

        $tasksChannelAssign = [
            'tasks.view',
            'tasks.manage',
            'tasks.assign',
        ];

        $tasksChannelLimited = [
            'tasks.view',
            'tasks.manage',
        ];

        $inAppNotifications = [
            'notifications.view',
        ];
        $auditEnterprise = [
            'audit.view',
            'audit.export',
        ];
        $demoLinksFull = [
            'demo_links.view',
            'demo_links.manage',
            'demo_links.share',
            'demo_links.manage_all',
        ];

        $demoLinksChannelShare = [
            'demo_links.view',
            'demo_links.manage',
            'demo_links.share',
        ];

        $demoLinksChannelLimited = [
            'demo_links.view',
            'demo_links.manage',
        ];

        $emailSettingsAdmin = [
            'email_settings.view',
            'email_settings.manage',
        ];

        $permissions = match ($role) {
            Role::CODE_GLOBAL_ADMIN => array_merge([
                'tenant.manage',
                'users.manage',
                'teams.manage',
                'products.manage',
                'contacts.manage',
                'companies.manage',
                'deals.manage',
                'quotes.manage',
                'payments.manage',
                'invoices.manage',
                'organizations.manage',
                'organizations.dashboard.view',
                'prm.programs.manage',
                'prm.licenses.manage',
                'prm.commissions.manage',
                'prm.resources.manage',
                'prm.resources.view',
                'prm.partner.dashboard.view',
                'prm.resellers.manage',
            ], $payoutFinance, $tasksFull, $inAppNotifications, $demoLinksFull, $emailSettingsAdmin, $auditEnterprise),
            Role::CODE_COMPANY_ADMIN => array_merge([
                'users.manage',
                'teams.manage',
                'products.manage',
                'contacts.manage',
                'companies.manage',
                'deals.manage',
                'quotes.manage',
                'payments.manage',
                'invoices.manage',
                'organizations.manage',
                'organizations.dashboard.view',
                'prm.programs.manage',
                'prm.licenses.manage',
                'prm.commissions.manage',
                'prm.resources.manage',
                'prm.resources.view',
                'prm.partner.dashboard.view',
                'prm.resellers.manage',
            ], $payoutFinance, $tasksFull, $inAppNotifications, $demoLinksFull, $emailSettingsAdmin, $auditEnterprise),
            Role::CODE_FINANCE_ADMIN => array_merge([
                'payments.view',
                'invoices.view',
                'organizations.dashboard.view',
                'prm.commissions.manage',
                'prm.commissions.view',
            ], $payoutFinance, $tasksFull, $inAppNotifications, $demoLinksFull, $emailSettingsAdmin),
            Role::CODE_PARTNER_ADMIN => array_merge([
                'contacts.view',
                'companies.view',
                'companies.create',
                'deals.view',
                'deals.create',
                'quotes.view',
                'quotes.create',
                'payments.view',
                'invoices.view',
                'organizations.dashboard.view',
                'prm.leads.manage',
                'prm.opportunities.manage',
                'prm.resources.view',
                'prm.commissions.view',
                'prm.licenses.view',
                'prm.licenses.consume',
                'prm.partner.dashboard.view',
                'prm.resellers.manage',
                'prm.payouts.view',
                'prm.payout.accounts.manage',
            ], $tasksChannelAssign, $inAppNotifications, $demoLinksChannelShare, $emailSettingsAdmin),
            Role::CODE_PARTNER_SALES_MANAGER => array_merge([
                'contacts.view',
                'companies.view',
                'companies.create',
                'deals.view',
                'deals.create',
                'quotes.view',
                'quotes.create',
                'payments.view',
                'invoices.view',
                'prm.leads.manage',
                'prm.opportunities.manage',
                'prm.resources.view',
                'prm.commissions.view',
                'prm.licenses.view',
                'prm.partner.dashboard.view',
            ], $tasksChannelAssign, $inAppNotifications, $demoLinksChannelShare),
            Role::CODE_PARTNER_SALES_CONSULTANT => array_merge([
                'contacts.view',
                'companies.view',
                'companies.create',
                'deals.view',
                'deals.create',
                'quotes.view',
                'quotes.create',
                'payments.view',
                'invoices.view',
                'prm.leads.manage',
                'prm.opportunities.manage',
                'prm.resources.view',
                'prm.commissions.view',
                'prm.licenses.view',
                'prm.partner.dashboard.view',
            ], $tasksChannelLimited, $inAppNotifications, $demoLinksChannelLimited),
            Role::CODE_RESELLER_ADMIN => array_merge([
                'contacts.view',
                'companies.view',
                'companies.create',
                'deals.view',
                'deals.create',
                'quotes.view',
                'quotes.create',
                'payments.view',
                'invoices.view',
                'organizations.dashboard.view',
                'prm.leads.manage',
                'prm.opportunities.manage',
                'prm.resources.view',
                'prm.commissions.view',
                'prm.licenses.view',
                'prm.licenses.consume',
                'prm.partner.dashboard.view',
                'organization.users.manage',
                'prm.payouts.view',
                'prm.payout.accounts.manage',
            ], $tasksChannelAssign, $inAppNotifications, $demoLinksChannelShare, $emailSettingsAdmin),
            Role::CODE_RESELLER_SALES_MANAGER => array_merge([
                'contacts.view',
                'companies.view',
                'companies.create',
                'deals.view',
                'deals.create',
                'quotes.view',
                'quotes.create',
                'payments.view',
                'invoices.view',
                'prm.leads.manage',
                'prm.opportunities.manage',
                'prm.resources.view',
                'prm.commissions.view',
                'prm.licenses.view',
                'prm.licenses.consume',
                'prm.partner.dashboard.view',
            ], $tasksChannelAssign, $inAppNotifications, $demoLinksChannelShare),
            Role::CODE_RESELLER_SALES_CONSULTANT => array_merge([
                'contacts.view',
                'companies.view',
                'deals.view',
                'quotes.view',
                'payments.view',
                'invoices.view',
                'prm.leads.manage',
                'prm.opportunities.manage',
                'prm.resources.view',
                'prm.partner.dashboard.view',
            ], $tasksChannelLimited, $inAppNotifications, $demoLinksChannelLimited),
            default => array_merge([
                'contacts.view',
                'companies.view',
                'deals.view',
                'quotes.view',
            ], $tasksChannelLimited, $demoLinksChannelLimited),
        };

        return array_values(array_unique($permissions));
    }

    /**
     * @return array{id: int|null, type: string|null, parent_id: int|null}
     */
    public function organization(User $user): array
    {
        $org = $user->organizationAssignment?->organization;

        return [
            'id' => $org?->id,
            'type' => $org?->type,
            'parent_id' => $org?->parent_organization_id,
        ];
    }

    public function navigationProfile(User $user): string
    {
        return match ($user->currentRoleCode()) {
            Role::CODE_GLOBAL_ADMIN => 'global_admin',
            Role::CODE_COMPANY_ADMIN => 'company_admin',
            Role::CODE_FINANCE_ADMIN => 'finance_admin',
            Role::CODE_PARTNER_ADMIN => 'partner_admin',
            Role::CODE_PARTNER_SALES_MANAGER, Role::CODE_PARTNER_SALES_CONSULTANT => 'partner_sales',
            Role::CODE_RESELLER_ADMIN => 'reseller_admin',
            Role::CODE_RESELLER_SALES_MANAGER => 'reseller_sales',
            Role::CODE_RESELLER_SALES_CONSULTANT => 'reseller_sales',
            default => 'crm_user',
        };
    }

    /**
     * @return array<string, bool>
     */
    public function featureFlags(User $user): array
    {
        $isPartner = $user->isPartnerPortalEligible();

        return [
            'prm_enabled' => $isPartner || $user->isCompanyAdmin() || $user->isGlobalAdmin(),
            'prm_partner_modules' => $isPartner,
            'prm_admin_modules' => $user->isCompanyAdmin() || $user->isGlobalAdmin() || $user->isFinanceAdmin(),
            'auto_verify_invited_users' => (bool) config('prm.auto_verify_invited_users', false),
            'invite_verification_required' => ! (bool) config('prm.auto_verify_invited_users', false),
        ];
    }
}
