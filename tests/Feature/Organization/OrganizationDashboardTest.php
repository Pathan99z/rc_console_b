<?php

namespace Tests\Feature\Organization;

use App\Models\CommissionAccrual;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\LicenseEntitlement;
use App\Models\Organization;
use App\Models\PaymentRecord;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserOrganizationAssignment;
use App\Services\Organization\OrganizationDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_view_partner_dashboard_overview(): void
    {
        [$tenant, $admin, $company, $partner, $reseller] = $this->seedPartnerWithReseller();
        $this->seedCrmData($tenant, $partner, $reseller, $admin);

        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/organizations/{$partner->id}/dashboard")->assertOk();

        $data = $response->json('data');
        $this->assertSame($partner->id, $data['organization']['id']);
        $this->assertTrue($data['includes_children']);
        $this->assertContains($reseller->id, $data['scope_organization_ids']);
        $this->assertGreaterThanOrEqual(1, $data['kpis']['crm']['contacts']);
        $this->assertArrayHasKey('generated_at', $data);
    }

    public function test_company_admin_can_view_reseller_dashboard(): void
    {
        [$tenant, $admin, , , $reseller] = $this->seedPartnerWithReseller();
        Sanctum::actingAs($admin);

        $this->getJson("/api/organizations/{$reseller->id}/dashboard")->assertOk()
            ->assertJsonPath('data.organization.type', Organization::TYPE_RESELLER)
            ->assertJsonPath('data.includes_children', false);
    }

    public function test_partner_admin_can_view_own_dashboard_and_child_reseller(): void
    {
        [$tenant, $admin, , $partner, $reseller] = $this->seedPartnerWithReseller();
        $partnerAdmin = $this->makeUser($tenant->id, Role::CODE_PARTNER_ADMIN, 'partner-dash@example.com', $partner->id);

        Sanctum::actingAs($partnerAdmin);
        $this->getJson("/api/organizations/{$partner->id}/dashboard")->assertOk();
        $this->getJson("/api/organizations/{$reseller->id}/dashboard")->assertOk();
    }

    public function test_partner_admin_cannot_view_sibling_partner_dashboard(): void
    {
        [$tenant, $admin, $company] = $this->seedTenantCompanyAdmin();
        $partnerA = $this->makeOrg($tenant, $admin, Organization::TYPE_PARTNER, $company->id, 'Partner A');
        $partnerB = $this->makeOrg($tenant, $admin, Organization::TYPE_PARTNER, $company->id, 'Partner B');
        $userA = $this->makeUser($tenant->id, Role::CODE_PARTNER_ADMIN, 'pa@example.com', $partnerA->id);

        Sanctum::actingAs($userA);
        $this->getJson("/api/organizations/{$partnerB->id}/dashboard")->assertForbidden();
    }

    public function test_reseller_admin_can_view_own_dashboard_only(): void
    {
        [$tenant, $admin, , $partner, $reseller] = $this->seedPartnerWithReseller();
        $resellerAdmin = $this->makeUser($reseller->tenant_id, Role::CODE_RESELLER_ADMIN, 'ra@example.com', $reseller->id);
        $sibling = $this->makeOrg($tenant, $admin, Organization::TYPE_RESELLER, $partner->id, 'Sibling');

        Sanctum::actingAs($resellerAdmin);
        $this->getJson("/api/organizations/{$reseller->id}/dashboard")->assertOk();
        $this->getJson("/api/organizations/{$partner->id}/dashboard")->assertForbidden();
        $this->getJson("/api/organizations/{$sibling->id}/dashboard")->assertForbidden();
    }

    public function test_consultant_cannot_access_organization_dashboard(): void
    {
        [, , , , $reseller] = $this->seedPartnerWithReseller();
        $consultant = $this->makeUser($reseller->tenant_id, Role::CODE_RESELLER_SALES_CONSULTANT, 'c@example.com', $reseller->id);

        Sanctum::actingAs($consultant);
        $this->getJson("/api/organizations/{$reseller->id}/dashboard")->assertForbidden();
    }

    public function test_tenant_isolation_on_dashboard(): void
    {
        [$tenantA, $adminA, , $partnerA] = $this->seedPartnerWithReseller();
        [$tenantB, $adminB] = $this->seedTenantCompanyAdmin();

        Sanctum::actingAs($adminB);
        $this->getJson("/api/organizations/{$partnerA->id}/dashboard")->assertNotFound();
    }

    public function test_dashboard_pipeline_revenue_commissions_endpoints(): void
    {
        [$tenant, $admin, , $partner] = $this->seedPartnerWithReseller();
        Sanctum::actingAs($admin);

        $this->getJson("/api/organizations/{$partner->id}/dashboard/pipeline")->assertOk()
            ->assertJsonStructure(['data' => ['pipeline' => ['stages', 'funnel']]]);
        $this->getJson("/api/organizations/{$partner->id}/dashboard/revenue")->assertOk();
        $this->getJson("/api/organizations/{$partner->id}/dashboard/commissions")->assertOk();
        $this->getJson("/api/organizations/{$partner->id}/dashboard/licenses")->assertOk();
        $this->getJson("/api/organizations/{$partner->id}/dashboard/activity")->assertOk();
        $this->getJson("/api/organizations/{$partner->id}/dashboard/team")->assertOk();
        $this->getJson("/api/organizations/{$partner->id}/dashboard/resources")->assertOk();
    }

    public function test_partner_portal_dashboard_backward_compatible_summary(): void
    {
        [, , , $partner] = $this->seedPartnerWithReseller();
        $partnerAdmin = $this->makeUser($partner->tenant_id, Role::CODE_PARTNER_ADMIN, 'portal@example.com', $partner->id);

        Sanctum::actingAs($partnerAdmin);
        $response = $this->getJson('/api/prm/partner/dashboard')->assertOk();

        $this->assertArrayHasKey('summary', $response->json('data'));
        $summary = $response->json('data.summary');
        $this->assertArrayHasKey('partner_organization_id', $summary);
        $this->assertArrayHasKey('counts', $summary);
        $this->assertArrayHasKey('commission_pending_total', $summary);
        $this->assertArrayHasKey('dashboard', $response->json('data'));
    }

    public function test_reseller_portal_dashboard_endpoint(): void
    {
        [, , , , $reseller] = $this->seedPartnerWithReseller();
        $resellerAdmin = $this->makeUser($reseller->tenant_id, Role::CODE_RESELLER_ADMIN, 'res-portal@example.com', $reseller->id);

        Sanctum::actingAs($resellerAdmin);
        $this->getJson('/api/prm/reseller/dashboard')->assertOk()
            ->assertJsonStructure(['data' => ['dashboard' => ['organization', 'kpis']]]);
    }

    public function test_dashboard_cache_returns_consistent_results(): void
    {
        Cache::flush();
        [$tenant, $admin, , $partner] = $this->seedPartnerWithReseller();
        Sanctum::actingAs($admin);

        $first = $this->getJson("/api/organizations/{$partner->id}/dashboard")->assertOk()->json('data.kpis.crm.contacts');
        $second = $this->getJson("/api/organizations/{$partner->id}/dashboard")->assertOk()->json('data.kpis.crm.contacts');
        $this->assertSame($first, $second);

        OrganizationDashboardService::flushCache($tenant->id, $partner->id);
    }

    public function test_legacy_partner_organization_id_fallback_on_deals(): void
    {
        [$tenant, $admin, , $partner] = $this->seedPartnerWithReseller();
        $pipeline = $this->seedPipeline($tenant, $admin);
        $stageId = (int) PipelineStage::query()->where('pipeline_id', $pipeline->id)->value('id');

        Deal::query()->create([
            'tenant_id' => $tenant->id,
            'channel_organization_id' => null,
            'partner_organization_id' => $partner->id,
            'name' => 'Legacy Deal',
            'contact_id' => Contact::query()->create([
                'tenant_id' => $tenant->id,
                'first_name' => 'L',
                'email' => 'legacy@example.com',
                'created_by_user_id' => $admin->id,
                'updated_by_user_id' => $admin->id,
                'lifecycle_stage' => Contact::STAGE_LEAD,
            ])->id,
            'owner_user_id' => $admin->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $stageId,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'status' => Deal::STATUS_OPEN,
        ]);

        Sanctum::actingAs($admin);
        $count = $this->getJson("/api/organizations/{$partner->id}/dashboard")->assertOk()
            ->json('data.kpis.crm.deals');
        $this->assertGreaterThanOrEqual(1, $count);
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Organization, 3: Organization, 4: Organization}
     */
    private function seedPartnerWithReseller(): array
    {
        [$tenant, $admin, $company] = $this->seedTenantCompanyAdmin();
        $partner = $this->makeOrg($tenant, $admin, Organization::TYPE_PARTNER, $company->id, 'Partner');
        $reseller = $this->makeOrg($tenant, $admin, Organization::TYPE_RESELLER, $partner->id, 'Reseller', Organization::CHANNEL_MODE_PARTNER_MANAGED);

        return [$tenant, $admin, $company, $partner, $reseller];
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Organization}
     */
    private function seedTenantCompanyAdmin(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Dash Tenant', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'CA',
            'email' => 'ca-dash-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $company = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Co',
            'display_name' => 'Co',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        return [$tenant, $admin, $company];
    }

    private function makeOrg(Tenant $tenant, User $admin, string $type, int $parentId, string $name, ?string $channelMode = null): Organization
    {
        return Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $parentId,
            'type' => $type,
            'channel_mode' => $channelMode,
            'legal_name' => $name.' Legal',
            'display_name' => $name,
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    private function makeUser(int $tenantId, string $role, string $email, int $orgId): User
    {
        $user = User::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'U',
            'email' => $email,
            'password' => 'secret123',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        UserOrganizationAssignment::query()->create([
            'user_id' => $user->id,
            'organization_id' => $orgId,
        ]);

        return $user;
    }

    private function seedCrmData(Tenant $tenant, Organization $partner, Organization $reseller, User $admin): void
    {
        Contact::query()->create([
            'tenant_id' => $tenant->id,
            'channel_organization_id' => $reseller->id,
            'first_name' => 'C',
            'email' => 'c-dash@example.com',
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);

        $pipeline = $this->seedPipeline($tenant, $admin);
        $stageId = (int) PipelineStage::query()->where('pipeline_id', $pipeline->id)->value('id');
        $contact = Contact::query()->create([
            'tenant_id' => $tenant->id,
            'channel_organization_id' => $reseller->id,
            'first_name' => 'D',
            'email' => 'deal@example.com',
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);
        $deal = Deal::query()->create([
            'tenant_id' => $tenant->id,
            'channel_organization_id' => $reseller->id,
            'partner_organization_id' => $reseller->id,
            'name' => 'Dash Deal',
            'contact_id' => $contact->id,
            'owner_user_id' => $admin->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $stageId,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'status' => Deal::STATUS_WON,
            'estimated_value' => 1000,
        ]);
        $quote = Quote::query()->create([
            'tenant_id' => $tenant->id,
            'channel_organization_id' => $reseller->id,
            'deal_id' => $deal->id,
            'contact_id' => $contact->id,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'public_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'quote_number' => 'Q-100',
            'status' => Quote::STATUS_ACCEPTED,
            'payment_status' => Quote::PAYMENT_STATUS_PAID,
            'subtotal' => 100,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 100,
            'currency_code' => 'ZAR',
        ]);
        PaymentRecord::query()->create([
            'tenant_id' => $tenant->id,
            'quote_id' => $quote->id,
            'amount' => 100,
            'currency_code' => 'ZAR',
            'status' => PaymentRecord::STATUS_SUCCESS,
        ]);
        CommissionAccrual::query()->create([
            'tenant_id' => $tenant->id,
            'partner_organization_id' => $partner->id,
            'base_amount' => 100,
            'commission_amount' => 10,
            'currency_code' => 'ZAR',
            'status' => CommissionAccrual::STATUS_PENDING,
        ]);
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'name' => 'P',
            'sku' => 'P1',
            'unit_price' => 10,
            'tax_rate' => 0,
            'status' => Product::STATUS_ACTIVE,
        ]);
        LicenseEntitlement::query()->create([
            'tenant_id' => $tenant->id,
            'holder_organization_id' => $reseller->id,
            'product_id' => $product->id,
            'units_total' => 10,
            'units_consumed' => 2,
            'created_by_user_id' => $admin->id,
        ]);
    }

    private function seedPipeline(Tenant $tenant, User $actor): Pipeline
    {
        $pipeline = Pipeline::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $actor->id,
            'name' => 'P',
            'status' => Pipeline::STATUS_ACTIVE,
        ]);
        PipelineStage::query()->create([
            'tenant_id' => $tenant->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'S1',
            'stage_order' => 1,
            'status' => PipelineStage::STATUS_ACTIVE,
        ]);

        return $pipeline;
    }
}
