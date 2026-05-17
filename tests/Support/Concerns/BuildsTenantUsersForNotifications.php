<?php

declare(strict_types=1);

namespace Tests\Support\Concerns;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserOrganizationAssignment;

trait BuildsTenantUsersForNotifications
{
    protected function makeActiveTenant(?string $name = null): Tenant
    {
        return Tenant::factory()->create([
            'name' => $name ?? 'Notify Tenant '.uniqid('', true),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeCompanyAdmin(Tenant $tenant, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'name' => 'Company Admin',
            'email' => 'ca-notify-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ], $overrides));
    }

    /**
     * CRM user role — no inbox permission (notifications.view absent).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeCrmPlainUser(Tenant $tenant, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'name' => 'CRM User',
            'email' => 'crm-user-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_USER,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeGlobalAdmin(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'tenant_id' => null,
            'name' => 'Global Admin',
            'email' => 'ga-notify-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_GLOBAL_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ], $overrides));
    }

    protected function assignUserOrganization(User $user, int $organizationId): void
    {
        UserOrganizationAssignment::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['organization_id' => $organizationId]
        );
    }

    protected function seedCompanyPartnerResellerHierarchy(Tenant $tenant, User $companyAdmin): array
    {
        $company = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Co',
            'display_name' => 'Co',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $partner = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner',
            'display_name' => 'Partner',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $reseller = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $partner->id,
            'type' => Organization::TYPE_RESELLER,
            'channel_mode' => Organization::CHANNEL_MODE_PARTNER_MANAGED,
            'legal_name' => 'Reseller',
            'display_name' => 'Reseller',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        return compact('company', 'partner', 'reseller');
    }
}
