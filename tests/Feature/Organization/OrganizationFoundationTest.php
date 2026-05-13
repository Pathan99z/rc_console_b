<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserOrganizationAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const ORGANIZATIONS_ENDPOINT = '/api/organizations';

    private const TENANT_NAME = 'Tenant A';

    public function test_company_admin_can_create_partner_and_reseller_hierarchy(): void
    {
        [$tenant, $companyAdmin] = $this->tenantWithCompanyAdmin();
        Sanctum::actingAs($companyAdmin);

        $company = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Root Company Legal',
            'display_name' => 'Root Company',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        $partnerResponse = $this->postJson(self::ORGANIZATIONS_ENDPOINT, [
            'type' => Organization::TYPE_PARTNER,
            'parent_organization_id' => $company->id,
            'legal_name' => 'Partner Legal',
            'display_name' => 'Partner One',
            'email' => 'partner1@example.com',
            'onboarding_status' => Organization::ONBOARDING_PENDING_REVIEW,
        ]);
        $partnerResponse->assertCreated();

        $partnerId = (int) $partnerResponse->json('data.organization.id');

        $resellerResponse = $this->postJson(self::ORGANIZATIONS_ENDPOINT, [
            'type' => Organization::TYPE_RESELLER,
            'parent_organization_id' => $partnerId,
            'legal_name' => 'Reseller Legal',
            'display_name' => 'Reseller One',
            'onboarding_status' => Organization::ONBOARDING_PENDING_REVIEW,
        ]);
        $resellerResponse->assertCreated();

        $this->assertDatabaseHas('organizations', [
            'id' => $partnerId,
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_PARTNER,
            'parent_organization_id' => $company->id,
        ]);
        $this->assertDatabaseHas('organizations', [
            'id' => (int) $resellerResponse->json('data.organization.id'),
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_RESELLER,
            'parent_organization_id' => $partnerId,
        ]);
    }

    public function test_partner_admin_can_only_create_reseller_under_own_partner(): void
    {
        [$tenant, $companyAdmin] = $this->tenantWithCompanyAdmin();
        $company = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Root',
            'display_name' => 'Root',
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $partnerA = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner A Legal',
            'display_name' => 'Partner A',
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $partnerB = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner B Legal',
            'display_name' => 'Partner B',
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        $partnerAdmin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Partner Admin',
            'email' => 'partner-admin@example.com',
            'password' => 'secret123',
            'role' => 'partner_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $this->assignUserOrganization($partnerAdmin, $partnerA->id);

        Sanctum::actingAs($partnerAdmin);

        $this->postJson(self::ORGANIZATIONS_ENDPOINT, [
            'type' => Organization::TYPE_RESELLER,
            'parent_organization_id' => $partnerA->id,
            'legal_name' => 'R1 Legal',
            'display_name' => 'R1',
        ])->assertCreated();

        $this->postJson(self::ORGANIZATIONS_ENDPOINT, [
            'type' => Organization::TYPE_RESELLER,
            'parent_organization_id' => $partnerB->id,
            'legal_name' => 'R2 Legal',
            'display_name' => 'R2',
        ])->assertStatus(422);
    }

    public function test_company_admin_can_create_partner_without_parent_organization_id(): void
    {
        [$tenant, $companyAdmin] = $this->tenantWithCompanyAdmin();
        $company = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Tenant Co Legal',
            'display_name' => 'Tenant Co',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        Sanctum::actingAs($companyAdmin);

        $response = $this->postJson(self::ORGANIZATIONS_ENDPOINT, [
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner Legal',
            'display_name' => 'Partner No Parent Field',
            'email' => 'partner-no-parent@example.com',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('organizations', [
            'id' => (int) $response->json('data.organization.id'),
            'type' => Organization::TYPE_PARTNER,
            'parent_organization_id' => $company->id,
        ]);
    }

    public function test_company_admin_partner_parent_prefers_user_linked_company_organization(): void
    {
        [$tenant, $companyAdmin] = $this->tenantWithCompanyAdmin();
        Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'First Co Legal',
            'display_name' => 'First Co',
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $preferredCompany = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Preferred Co Legal',
            'display_name' => 'Preferred Co',
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        $this->assignUserOrganization($companyAdmin, $preferredCompany->id);

        Sanctum::actingAs($companyAdmin->fresh());

        $response = $this->postJson(self::ORGANIZATIONS_ENDPOINT, [
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner Legal',
            'display_name' => 'Partner Preferred Parent',
        ]);

        $response->assertCreated();
        $this->assertSame($preferredCompany->id, (int) $response->json('data.organization.parent_organization_id'));
    }

    public function test_company_admin_create_partner_auto_seeds_root_company_from_tenant_name(): void
    {
        [$tenant, $companyAdmin] = $this->tenantWithCompanyAdmin();
        $this->assertSame(
            0,
            Organization::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('type', Organization::TYPE_COMPANY)->count()
        );

        Sanctum::actingAs($companyAdmin);

        $response = $this->postJson(self::ORGANIZATIONS_ENDPOINT, [
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner Legal',
            'display_name' => 'Partner From Tenant Root',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('organizations', [
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'display_name' => self::TENANT_NAME,
        ]);
        $rootCompanyId = (int) Organization::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('type', Organization::TYPE_COMPANY)
            ->orderBy('id')
            ->value('id');
        $this->assertDatabaseHas('organizations', [
            'id' => (int) $response->json('data.organization.id'),
            'type' => Organization::TYPE_PARTNER,
            'parent_organization_id' => $rootCompanyId,
        ]);
    }

    public function test_partner_admin_can_create_reseller_without_parent_organization_id(): void
    {
        [$tenant, $companyAdmin] = $this->tenantWithCompanyAdmin();
        $company = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Root',
            'display_name' => 'Root',
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $partner = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'P Legal',
            'display_name' => 'Partner',
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        $partnerAdmin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Partner Admin',
            'email' => 'partner-admin-reseller-implicit@example.com',
            'password' => 'secret123',
            'role' => 'partner_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $this->assignUserOrganization($partnerAdmin, $partner->id);

        Sanctum::actingAs($partnerAdmin);

        $response = $this->postJson(self::ORGANIZATIONS_ENDPOINT, [
            'type' => Organization::TYPE_RESELLER,
            'legal_name' => 'R Legal',
            'display_name' => 'Reseller Implicit Parent',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('organizations', [
            'id' => (int) $response->json('data.organization.id'),
            'type' => Organization::TYPE_RESELLER,
            'parent_organization_id' => $partner->id,
        ]);
    }

    public function test_visibility_scope_for_company_partner_and_reseller_roles(): void
    {
        [$tenant, $companyAdmin] = $this->tenantWithCompanyAdmin();
        $company = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Root Legal',
            'display_name' => 'Root',
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $partner = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner Legal Name',
            'display_name' => 'Partner',
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $reseller = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $partner->id,
            'type' => Organization::TYPE_RESELLER,
            'legal_name' => 'Reseller Legal Name',
            'display_name' => 'Reseller',
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $otherReseller = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $partner->id,
            'type' => Organization::TYPE_RESELLER,
            'legal_name' => 'Reseller B Legal',
            'display_name' => 'Reseller B',
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        $partnerAdmin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Partner Admin',
            'email' => 'partner-scope@example.com',
            'password' => 'secret123',
            'role' => 'partner_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $this->assignUserOrganization($partnerAdmin, $partner->id);

        $resellerUser = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Reseller Admin',
            'email' => 'reseller-scope@example.com',
            'password' => 'secret123',
            'role' => 'reseller_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $this->assignUserOrganization($resellerUser, $reseller->id);

        Sanctum::actingAs($companyAdmin);
        $companyList = $this->getJson(self::ORGANIZATIONS_ENDPOINT)->assertOk()->json('data.items');
        $this->assertCount(4, $companyList);

        Sanctum::actingAs($partnerAdmin);
        $partnerItems = collect($this->getJson(self::ORGANIZATIONS_ENDPOINT)->assertOk()->json('data.items'))->pluck('id');
        $this->assertTrue($partnerItems->contains($partner->id));
        $this->assertTrue($partnerItems->contains($reseller->id));
        $this->assertTrue($partnerItems->contains($otherReseller->id));
        $this->assertFalse($partnerItems->contains($company->id));

        Sanctum::actingAs($resellerUser);
        $resellerItems = $this->getJson(self::ORGANIZATIONS_ENDPOINT)->assertOk()->json('data.items');
        $this->assertCount(1, $resellerItems);
        $this->assertSame($reseller->id, (int) $resellerItems[0]['id']);
    }

    public function test_tenant_isolation_prevents_cross_tenant_organization_access(): void
    {
        [, $adminA] = $this->tenantWithCompanyAdmin(self::TENANT_NAME, 'adminA@example.com');
        [$tenantB, $adminB] = $this->tenantWithCompanyAdmin('Tenant B', 'adminB@example.com');
        $organizationB = Organization::query()->create([
            'tenant_id' => $tenantB->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Tenant B Company Legal',
            'display_name' => 'Tenant B Company',
            'created_by_user_id' => $adminB->id,
            'updated_by_user_id' => $adminB->id,
        ]);

        Sanctum::actingAs($adminA);
        $this->getJson("/api/organizations/{$organizationB->id}")->assertStatus(404);
    }

    public function test_approval_workflow_endpoints_update_onboarding_status(): void
    {
        [$tenant, $companyAdmin] = $this->tenantWithCompanyAdmin();
        $company = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Root Legal',
            'display_name' => 'Root',
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $partner = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_PARTNER,
            'parent_organization_id' => $company->id,
            'legal_name' => 'Partner Legal Name',
            'display_name' => 'Partner',
            'onboarding_status' => Organization::ONBOARDING_PENDING_REVIEW,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        Sanctum::actingAs($companyAdmin);
        $this->postJson("/api/organizations/{$partner->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.organization.onboarding_status', Organization::ONBOARDING_ACTIVE);

        $this->postJson("/api/organizations/{$partner->id}/suspend")
            ->assertOk()
            ->assertJsonPath('data.organization.onboarding_status', Organization::ONBOARDING_SUSPENDED);

        $this->postJson("/api/organizations/{$partner->id}/reject", ['reason' => 'Incomplete docs'])
            ->assertOk()
            ->assertJsonPath('data.organization.onboarding_status', Organization::ONBOARDING_REJECTED);
    }

    public function test_parent_options_for_partner_lists_only_company_organizations_in_tenant(): void
    {
        [$tenant, $companyAdmin] = $this->tenantWithCompanyAdmin();
        Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Co A Legal',
            'display_name' => 'Company A',
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => null,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner Legal',
            'display_name' => 'Partner X',
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        Sanctum::actingAs($companyAdmin);

        $response = $this->getJson(self::ORGANIZATIONS_ENDPOINT.'/parent-options?child_type=partner');
        $response->assertOk();
        $types = collect($response->json('data.items'))->pluck('type')->unique()->values()->all();
        $this->assertSame([Organization::TYPE_COMPANY], $types);
    }

    public function test_parent_options_for_reseller_lists_only_partners_for_company_admin(): void
    {
        [$tenant, $companyAdmin] = $this->tenantWithCompanyAdmin();
        $company = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Root Legal',
            'display_name' => 'Root',
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'P Legal',
            'display_name' => 'Partner One',
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        Sanctum::actingAs($companyAdmin);

        $response = $this->getJson(self::ORGANIZATIONS_ENDPOINT.'/parent-options?child_type=reseller');
        $response->assertOk();
        $types = collect($response->json('data.items'))->pluck('type')->unique()->values()->all();
        $this->assertSame([Organization::TYPE_PARTNER], $types);
    }

    public function test_partner_admin_parent_options_for_reseller_returns_only_own_partner(): void
    {
        [$tenant, $companyAdmin] = $this->tenantWithCompanyAdmin();
        $company = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Root Legal',
            'display_name' => 'Root',
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $partnerA = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'PA Legal',
            'display_name' => 'Partner A',
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'PB Legal',
            'display_name' => 'Partner B',
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        $partnerAdmin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Partner Admin',
            'email' => 'partner-admin-parent@example.com',
            'password' => 'secret123',
            'role' => 'partner_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $this->assignUserOrganization($partnerAdmin, $partnerA->id);

        Sanctum::actingAs($partnerAdmin);

        $response = $this->getJson(self::ORGANIZATIONS_ENDPOINT.'/parent-options?child_type=reseller');
        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertSame([$partnerA->id], $ids);
    }

    public function test_partner_admin_cannot_fetch_parent_options_for_partner_child_type(): void
    {
        [$tenant, $companyAdmin] = $this->tenantWithCompanyAdmin();
        $company = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Root Legal',
            'display_name' => 'Root',
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $partner = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'P Legal',
            'display_name' => 'Partner',
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        $partnerAdmin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Partner Admin',
            'email' => 'partner-admin-no-partner-create@example.com',
            'password' => 'secret123',
            'role' => 'partner_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $this->assignUserOrganization($partnerAdmin, $partner->id);

        Sanctum::actingAs($partnerAdmin);

        $this->getJson(self::ORGANIZATIONS_ENDPOINT.'/parent-options?child_type=partner')->assertStatus(422);
    }

    public function test_role_assignment_logs_audit_record(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $globalAdmin = User::query()->create([
            'tenant_id' => null,
            'name' => 'Global Admin',
            'email' => 'global-admin-role@example.com',
            'password' => 'secret123',
            'role' => 'global_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $target = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Target',
            'email' => 'target-role@example.com',
            'password' => 'secret123',
            'role' => 'user',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($globalAdmin);
        $this->patchJson("/api/users/{$target->id}/role", [
            'role' => 'partner_sales_manager',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'user',
            'action' => 'user.role.changed',
            'entity_type' => 'user',
            'entity_id' => $target->id,
        ]);
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function tenantWithCompanyAdmin(string $tenantName = self::TENANT_NAME, string $email = 'company-admin-org@example.com'): array
    {
        $tenant = Tenant::query()->create(['name' => $tenantName, 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Company Admin',
            'email' => $email,
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        return [$tenant, $admin];
    }

    private function assignUserOrganization(User $user, int $organizationId): void
    {
        UserOrganizationAssignment::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['organization_id' => $organizationId]
        );
        $user->unsetRelation('organizationAssignment');
    }
}
