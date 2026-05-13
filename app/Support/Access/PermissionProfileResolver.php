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

        $permissions = match ($role) {
            Role::CODE_GLOBAL_ADMIN => [
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
                'prm.programs.manage',
                'prm.licenses.manage',
                'prm.commissions.manage',
                'prm.resources.view',
                'prm.partner.dashboard.view',
                'prm.resellers.manage',
            ],
            Role::CODE_COMPANY_ADMIN => [
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
                'prm.programs.manage',
                'prm.licenses.manage',
                'prm.commissions.manage',
                'prm.resources.view',
                'prm.partner.dashboard.view',
                'prm.resellers.manage',
            ],
            Role::CODE_PARTNER_ADMIN => [
                'contacts.view',
                'companies.view',
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
                'prm.resellers.manage',
            ],
            Role::CODE_PARTNER_SALES_MANAGER, Role::CODE_PARTNER_SALES_CONSULTANT => [
                'contacts.view',
                'companies.view',
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
            ],
            Role::CODE_RESELLER_ADMIN => [
                'contacts.view',
                'companies.view',
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
            ],
            Role::CODE_RESELLER_SALES_CONSULTANT => [
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
            ],
            default => [
                'contacts.view',
                'companies.view',
                'deals.view',
                'quotes.view',
            ],
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
            Role::CODE_PARTNER_ADMIN => 'partner_admin',
            Role::CODE_PARTNER_SALES_MANAGER, Role::CODE_PARTNER_SALES_CONSULTANT => 'partner_sales',
            Role::CODE_RESELLER_ADMIN => 'reseller_admin',
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
            'prm_admin_modules' => $user->isCompanyAdmin() || $user->isGlobalAdmin(),
            'auto_verify_invited_users' => (bool) config('prm.auto_verify_invited_users', false),
            'invite_verification_required' => ! (bool) config('prm.auto_verify_invited_users', false),
        ];
    }
}
