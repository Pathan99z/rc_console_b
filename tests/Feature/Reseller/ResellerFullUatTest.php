<?php

namespace Tests\Feature\Reseller;

use App\Models\CommissionAccrual;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\LicenseEntitlement;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\PartnerProgram;
use App\Models\PaymentRecord;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserOrganizationAssignment;
use App\Support\Access\PermissionProfileResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end API UAT for reseller / partner / PRM flows.
 */
class ResellerFullUatTest extends TestCase
{
    use RefreshDatabase;

    public function test_scenario_1_direct_reseller_full_api_flow(): void
    {
        Config::set('prm.auto_verify_invited_users', true);

        [$tenant, $companyAdmin, $company] = $this->seedTenantCompanyAdmin();
        Sanctum::actingAs($companyAdmin);

        $create = $this->postJson('/api/organizations', [
            'type' => Organization::TYPE_RESELLER,
            'parent_organization_id' => $company->id,
            'legal_name' => 'UAT Direct Legal',
            'display_name' => 'UAT Direct Reseller',
            'onboarding_status' => Organization::ONBOARDING_PENDING_REVIEW,
        ])->assertCreated();

        $resellerId = (int) $create->json('data.organization.id');
        $this->assertSame(Organization::CHANNEL_MODE_DIRECT, $create->json('data.organization.channel_mode'));

        $this->postJson("/api/organizations/{$resellerId}/approve")->assertOk();
        $this->assertDatabaseHas('organizations', [
            'id' => $resellerId,
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
        ]);

        $invite = $this->postJson("/api/organizations/{$resellerId}/users/invite", [
            'email' => 'uat-direct-admin@example.com',
            'role_code' => Role::CODE_RESELLER_ADMIN,
        ])->assertCreated();

        $plainToken = $invite->json('data.plain_token');
        $accept = $this->postJson('/api/prm/invitations/accept', [
            'token' => $plainToken,
            'name' => 'UAT Direct Admin',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => true,
        ])->assertOk();

        $resellerAdminId = (int) $accept->json('data.user.id');
        $resellerAdmin = User::query()->findOrFail($resellerAdminId);
        $this->assertSame($resellerId, (int) $resellerAdmin->primaryOrganizationId());

        Sanctum::actingAs($resellerAdmin);
        $this->getJson('/api/contacts')->assertOk();

        $pipeline = $this->seedPipeline($tenant, $companyAdmin);
        $stageId = (int) PipelineStage::query()->where('pipeline_id', $pipeline->id)->value('id');

        $companyResp = $this->postJson('/api/companies', [
            'name' => 'UAT Reseller Co',
            'email' => 'uat-reseller-co@example.com',
        ])->assertCreated();
        $companyRecordId = (int) $companyResp->json('data.company.id');
        $this->assertDatabaseHas('companies', [
            'id' => $companyRecordId,
            'channel_organization_id' => $resellerId,
        ]);

        $contactResp = $this->postJson('/api/contacts', [
            'first_name' => 'UAT',
            'last_name' => 'Contact',
            'email' => 'uat-contact@example.com',
            'company_id' => $companyRecordId,
        ])->assertCreated();
        $contactId = (int) $contactResp->json('data.contact.id');
        $this->assertDatabaseHas('contacts', [
            'id' => $contactId,
            'channel_organization_id' => $resellerId,
        ]);

        $dealResp = $this->postJson('/api/deals', [
            'name' => 'UAT Deal',
            'contact_id' => $contactId,
            'company_id' => $companyRecordId,
            'owner_user_id' => $resellerAdminId,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $stageId,
        ])->assertCreated();
        $dealId = (int) $dealResp->json('data.deal.id');
        $this->assertDatabaseHas('deals', [
            'id' => $dealId,
            'channel_organization_id' => $resellerId,
            'partner_organization_id' => $resellerId,
        ]);

        Sanctum::actingAs($companyAdmin);
        $this->getJson('/api/contacts')->assertOk()
            ->assertJsonFragment(['email' => 'uat-contact@example.com']);
    }

    public function test_scenario_2_partner_managed_reseller_partner_sees_child_crm(): void
    {
        [$tenant, $companyAdmin, $company, $partner, $reseller] = $this->seedPartnerManagedReseller();
        $partnerAdmin = $this->makeChannelUser($tenant, Role::CODE_PARTNER_ADMIN, 'uat-partner-admin@example.com', $partner->id);
        $resellerAdmin = $this->makeChannelUser($tenant, Role::CODE_RESELLER_ADMIN, 'uat-managed-reseller@example.com', $reseller->id);

        $pipeline = $this->seedPipeline($tenant, $companyAdmin);
        $stageId = (int) PipelineStage::query()->where('pipeline_id', $pipeline->id)->value('id');

        Sanctum::actingAs($resellerAdmin);
        $contactId = (int) $this->postJson('/api/contacts', [
            'first_name' => 'Child',
            'email' => 'child-contact@example.com',
        ])->assertCreated()->json('data.contact.id');

        $this->postJson('/api/deals', [
            'name' => 'Child Deal',
            'contact_id' => $contactId,
            'owner_user_id' => $resellerAdmin->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $stageId,
        ])->assertCreated();

        Sanctum::actingAs($partnerAdmin);
        $contacts = collect($this->getJson('/api/contacts')->assertOk()->json('data.items'));
        $this->assertTrue($contacts->pluck('email')->contains('child-contact@example.com'));
    }

    public function test_scenario_3_sibling_reseller_crm_isolation(): void
    {
        [$tenant, $companyAdmin, $company] = $this->seedTenantCompanyAdmin();
        $resellerA = $this->createResellerViaApi($companyAdmin, $company->id, 'Reseller A');
        $resellerB = $this->createResellerViaApi($companyAdmin, $company->id, 'Reseller B');
        $userA = $this->makeChannelUser($tenant, Role::CODE_RESELLER_ADMIN, 'reseller-a-iso@example.com', $resellerA);
        $userB = $this->makeChannelUser($tenant, Role::CODE_RESELLER_ADMIN, 'reseller-b-iso@example.com', $resellerB);

        $pipeline = $this->seedPipeline($tenant, $companyAdmin);
        $stageId = (int) PipelineStage::query()->where('pipeline_id', $pipeline->id)->value('id');

        Sanctum::actingAs($userA);
        $contactA = (int) $this->postJson('/api/contacts', [
            'first_name' => 'OnlyA',
            'email' => 'only-a@example.com',
        ])->assertCreated()->json('data.contact.id');

        Sanctum::actingAs($userB);
        $listB = collect($this->getJson('/api/contacts')->assertOk()->json('data.items'));
        $this->assertFalse($listB->pluck('email')->contains('only-a@example.com'));
        $this->getJson("/api/contacts/{$contactA}")->assertNotFound();
    }

    public function test_scenario_4_onboarding_gate_blocks_all_crm_and_partner_portal(): void
    {
        [$tenant, , , $reseller] = $this->seedDirectReseller(pending: true);
        $user = $this->makeChannelUser($tenant, Role::CODE_RESELLER_ADMIN, 'pending-gate@example.com', $reseller->id);
        Sanctum::actingAs($user);

        foreach (['/api/contacts', '/api/companies', '/api/deals', '/api/quotes'] as $path) {
            $this->getJson($path)->assertStatus(403);
        }
        $this->getJson('/api/prm/partner/dashboard')->assertStatus(403);
    }

    public function test_scenario_5_consultant_cannot_invite_users(): void
    {
        [$tenant, , , $reseller] = $this->seedDirectReseller();
        $consultant = $this->makeChannelUser($tenant, Role::CODE_RESELLER_SALES_CONSULTANT, 'consultant-uat@example.com', $reseller->id);
        Sanctum::actingAs($consultant);

        $this->postJson("/api/organizations/{$reseller->id}/users/invite", [
            'email' => 'blocked@example.com',
            'role_code' => Role::CODE_RESELLER_SALES_CONSULTANT,
        ])->assertStatus(422);
    }

    public function test_scenario_5_consultant_permissions_profile(): void
    {
        [$tenant, , , $reseller] = $this->seedDirectReseller();
        $consultant = $this->makeChannelUser($tenant, Role::CODE_RESELLER_SALES_CONSULTANT, 'consultant-perm@example.com', $reseller->id);
        $perms = app(PermissionProfileResolver::class)->permissions($consultant);

        $this->assertContains('contacts.view', $perms);
        $this->assertContains('deals.view', $perms);
        $this->assertNotContains('organization.users.manage', $perms);
    }

    public function test_scenario_6_license_transfer_rejects_overdraw(): void
    {
        [$tenant, $companyAdmin, , $partner, $reseller] = $this->seedPartnerManagedReseller();
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'name' => 'Lic',
            'sku' => 'LIC-UAT',
            'unit_price' => 10,
            'tax_rate' => 0,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $parent = LicenseEntitlement::query()->create([
            'tenant_id' => $tenant->id,
            'holder_organization_id' => $partner->id,
            'units_total' => 5,
            'units_consumed' => 0,
            'product_id' => $product->id,
            'created_by_user_id' => $companyAdmin->id,
        ]);

        $partnerAdmin = $this->makeChannelUser($tenant, Role::CODE_PARTNER_ADMIN, 'partner-lic-uat@example.com', $partner->id);
        Sanctum::actingAs($partnerAdmin);

        $this->postJson('/api/prm/license-entitlements/transfer', [
            'from_entitlement_id' => $parent->id,
            'to_organization_id' => $reseller->id,
            'units' => 99,
        ])->assertStatus(422);
    }

    public function test_scenario_7_direct_reseller_license_activate(): void
    {
        [$tenant, $companyAdmin, , $reseller] = $this->seedDirectReseller();
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'name' => 'Direct Lic',
            'sku' => 'DL-1',
            'unit_price' => 10,
            'tax_rate' => 0,
            'status' => Product::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($companyAdmin);
        $entitlementId = (int) $this->postJson('/api/prm/license-entitlements', [
            'holder_organization_id' => $reseller->id,
            'product_id' => $product->id,
            'units_total' => 3,
        ])->assertCreated()->json('data.entitlement.id');

        $resellerAdmin = $this->makeChannelUser($tenant, Role::CODE_RESELLER_ADMIN, 'direct-lic-admin@example.com', $reseller->id);
        Sanctum::actingAs($resellerAdmin);

        $this->postJson("/api/prm/license-entitlements/{$entitlementId}/activate", [
            'units' => 1,
        ])->assertOk();

        $this->assertDatabaseHas('license_activations', [
            'license_entitlement_id' => $entitlementId,
            'units' => 1,
        ]);
    }

    public function test_scenario_9_partner_managed_reseller_cannot_enroll_directly(): void
    {
        [$tenant, $companyAdmin, , $partner, $reseller] = $this->seedPartnerManagedReseller();
        $program = PartnerProgram::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'managed-block',
            'name' => 'Managed Block',
            'tier_level' => 1,
            'default_commission_percent' => 10,
            'status' => PartnerProgram::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($companyAdmin);
        $this->postJson('/api/prm/programs/enroll', [
            'organization_id' => $partner->id,
            'partner_program_id' => $program->id,
        ])->assertCreated();

        $this->postJson('/api/prm/programs/enroll', [
            'organization_id' => $reseller->id,
            'partner_program_id' => $program->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_id']);
    }

    public function test_scenario_1_commission_list_visible_to_reseller(): void
    {
        [$tenant, $companyAdmin, $company, $reseller] = $this->seedDirectReseller();
        $resellerAdmin = $this->makeChannelUser($tenant, Role::CODE_RESELLER_ADMIN, 'comm-list@example.com', $reseller->id);

        CommissionAccrual::query()->create([
            'tenant_id' => $tenant->id,
            'partner_organization_id' => $reseller->id,
            'base_amount' => 1000,
            'commission_amount' => 100,
            'currency_code' => 'USD',
            'status' => CommissionAccrual::STATUS_PENDING,
            'rule_snapshot' => ['resolution_mode' => 'reseller_direct'],
        ]);

        Sanctum::actingAs($resellerAdmin);
        $items = $this->getJson('/api/prm/commission-accruals')->assertOk()->json('data.items');
        $this->assertNotEmpty($items);
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Organization}
     */
    private function seedTenantCompanyAdmin(): array
    {
        $tenant = Tenant::query()->create(['name' => 'UAT Tenant', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'CA',
            'email' => 'ca-uat-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $company = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Co Legal',
            'display_name' => 'Co',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        return [$tenant, $admin, $company];
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Organization, 3: Organization}
     */
    private function seedDirectReseller(bool $pending = false): array
    {
        [$tenant, $admin, $company] = $this->seedTenantCompanyAdmin();
        $reseller = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_RESELLER,
            'channel_mode' => Organization::CHANNEL_MODE_DIRECT,
            'legal_name' => 'Direct Legal',
            'display_name' => 'Direct',
            'onboarding_status' => $pending ? Organization::ONBOARDING_PENDING_REVIEW : Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        return [$tenant, $admin, $company, $reseller];
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Organization, 3: Organization, 4: Organization}
     */
    private function seedPartnerManagedReseller(): array
    {
        [$tenant, $admin, $company] = $this->seedTenantCompanyAdmin();
        $partner = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner Legal',
            'display_name' => 'Partner',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);
        $reseller = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $partner->id,
            'type' => Organization::TYPE_RESELLER,
            'channel_mode' => Organization::CHANNEL_MODE_PARTNER_MANAGED,
            'legal_name' => 'Managed Legal',
            'display_name' => 'Managed Reseller',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        return [$tenant, $admin, $company, $partner, $reseller];
    }

    private function createResellerViaApi(User $companyAdmin, int $companyId, string $name): int
    {
        Sanctum::actingAs($companyAdmin);

        return (int) $this->postJson('/api/organizations', [
            'type' => Organization::TYPE_RESELLER,
            'parent_organization_id' => $companyId,
            'legal_name' => $name.' Legal',
            'display_name' => $name,
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
        ])->assertCreated()->json('data.organization.id');
    }

    private function makeChannelUser(Tenant $tenant, string $role, string $email, int $organizationId): User
    {
        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Channel User',
            'email' => $email,
            'password' => 'secret123',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        UserOrganizationAssignment::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
        ]);

        return $user;
    }

    private function seedPipeline(Tenant $tenant, User $actor): Pipeline
    {
        $pipeline = Pipeline::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $actor->id,
            'name' => 'UAT Pipeline',
            'status' => Pipeline::STATUS_ACTIVE,
        ]);
        PipelineStage::query()->create([
            'tenant_id' => $tenant->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Stage 1',
            'stage_order' => 1,
            'status' => PipelineStage::STATUS_ACTIVE,
        ]);

        return $pipeline;
    }
}
