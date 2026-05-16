<?php

namespace Tests\Feature\Organization;

use App\Models\Collateral;
use App\Models\CollateralDownload;
use App\Models\CommissionAccrual;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\LicenseActivation;
use App\Models\LicenseEntitlement;
use App\Models\LicenseMovement;
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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationDashboardFullUatTest extends TestCase
{
    use RefreshDatabase;

    private const DASHBOARD_SECTIONS = [
        '',
        '/overview',
        '/pipeline',
        '/revenue',
        '/commissions',
        '/licenses',
        '/activity',
        '/team',
        '/resources',
    ];

    public function test_phase1_all_dashboard_endpoints_smoke_for_company_admin(): void
    {
        $ctx = $this->seedFullDataset();
        Sanctum::actingAs($ctx['admin']);

        foreach (self::DASHBOARD_SECTIONS as $suffix) {
            $path = "/api/organizations/{$ctx['partner']->id}/dashboard{$suffix}";
            $response = $this->getJson($path)->assertOk();
            $this->assertTrue($response->json('success'));
            $this->assertArrayHasKey('organization', $response->json('data'));
            $this->assertArrayHasKey('generated_at', $response->json('data'));
        }
    }

    public function test_phase1_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/organizations/1/dashboard')->assertUnauthorized();
    }

    public function test_phase1_invalid_date_range_returns_422(): void
    {
        $ctx = $this->seedFullDataset();
        Sanctum::actingAs($ctx['admin']);

        $this->getJson("/api/organizations/{$ctx['partner']->id}/dashboard?from=2026-05-10&to=2026-05-01")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);
    }

    public function test_phase1_invalid_date_format_returns_422(): void
    {
        $ctx = $this->seedFullDataset();
        Sanctum::actingAs($ctx['admin']);

        $this->getJson("/api/organizations/{$ctx['partner']->id}/dashboard?from=not-a-date")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from']);
    }

    public function test_phase2_company_admin_partner_aggregate_includes_child_reseller_crm(): void
    {
        $ctx = $this->seedFullDataset();
        Sanctum::actingAs($ctx['admin']);

        $partnerData = $this->getJson("/api/organizations/{$ctx['partner']->id}/dashboard")->assertOk()->json('data');
        $resellerData = $this->getJson("/api/organizations/{$ctx['reseller']->id}/dashboard")->assertOk()->json('data');

        $this->assertTrue($partnerData['includes_children']);
        $this->assertContains($ctx['reseller']->id, $partnerData['scope_organization_ids']);
        $this->assertFalse($resellerData['includes_children']);
        $this->assertSame([$ctx['reseller']->id], $resellerData['scope_organization_ids']);

        $this->assertGreaterThanOrEqual(2, $partnerData['kpis']['crm']['contacts']);
        $this->assertSame(1, $resellerData['kpis']['crm']['contacts']);
        $this->assertGreaterThanOrEqual(100, (float) $partnerData['kpis']['revenue']['total_revenue']);
    }

    public function test_phase2_company_admin_direct_reseller_dashboard(): void
    {
        $ctx = $this->seedFullDataset();
        Sanctum::actingAs($ctx['admin']);

        $data = $this->getJson("/api/organizations/{$ctx['directReseller']->id}/dashboard")->assertOk()->json('data');
        $this->assertSame(Organization::CHANNEL_MODE_DIRECT, $data['organization']['channel_mode']);
        $this->assertFalse($data['includes_children']);
        $this->assertSame(1, $data['kpis']['crm']['contacts']);
    }

    public function test_phase2_partner_revenue_by_child_organization_populated(): void
    {
        $ctx = $this->seedFullDataset();
        Sanctum::actingAs($ctx['admin']);

        $revenue = $this->getJson("/api/organizations/{$ctx['partner']->id}/dashboard/revenue")->assertOk()->json('data.revenue');
        $this->assertNotEmpty($revenue['by_child_organization']);
        $childIds = collect($revenue['by_child_organization'])->pluck('organization_id');
        $this->assertTrue($childIds->contains($ctx['reseller']->id));
    }

    public function test_phase3_partner_admin_sees_child_metrics_not_sibling_partner(): void
    {
        $ctx = $this->seedFullDataset();
        $partnerAdmin = $this->makeUser($ctx['tenant']->id, Role::CODE_PARTNER_ADMIN, 'pa-uat@example.com', $ctx['partner']->id);
        $otherPartner = $this->makeOrg($ctx['tenant'], $ctx['admin'], Organization::TYPE_PARTNER, $ctx['company']->id, 'Other Partner');

        Sanctum::actingAs($partnerAdmin);
        $this->getJson("/api/organizations/{$ctx['partner']->id}/dashboard")->assertOk();
        $this->getJson("/api/organizations/{$ctx['reseller']->id}/dashboard")->assertOk();
        $this->getJson("/api/organizations/{$otherPartner->id}/dashboard")->assertForbidden();
    }

    public function test_phase3_partner_commissions_include_beneficiary_totals(): void
    {
        $ctx = $this->seedFullDataset();
        Sanctum::actingAs($ctx['admin']);

        $commissions = $this->getJson("/api/organizations/{$ctx['partner']->id}/dashboard/commissions")->assertOk()->json('data.commissions');
        $this->assertGreaterThanOrEqual(10, (float) $commissions['totals']['pending']);
    }

    public function test_phase4_reseller_admin_portal_and_org_dashboard(): void
    {
        $ctx = $this->seedFullDataset();
        $resellerAdmin = $this->makeUser($ctx['tenant']->id, Role::CODE_RESELLER_ADMIN, 'ra-uat@example.com', $ctx['reseller']->id);

        Sanctum::actingAs($resellerAdmin);
        $this->getJson('/api/prm/reseller/dashboard')->assertOk();
        $this->getJson('/api/prm/reseller/navigation')->assertOk();
        $this->getJson("/api/organizations/{$ctx['reseller']->id}/dashboard")->assertOk();
        $this->getJson("/api/organizations/{$ctx['partner']->id}/dashboard")->assertForbidden();
    }

    public function test_phase5_partner_sales_manager_cannot_access_org_dashboard(): void
    {
        $ctx = $this->seedFullDataset();
        $manager = $this->makeUser($ctx['tenant']->id, Role::CODE_PARTNER_SALES_MANAGER, 'mgr@example.com', $ctx['partner']->id);

        Sanctum::actingAs($manager);
        $this->getJson("/api/organizations/{$ctx['partner']->id}/dashboard")->assertForbidden();
    }

    public function test_phase5_reseller_consultant_cannot_access_org_dashboard(): void
    {
        $ctx = $this->seedFullDataset();
        $consultant = $this->makeUser($ctx['tenant']->id, Role::CODE_RESELLER_SALES_CONSULTANT, 'con@example.com', $ctx['reseller']->id);

        Sanctum::actingAs($consultant);
        $this->getJson("/api/organizations/{$ctx['reseller']->id}/dashboard")->assertForbidden();
    }

    public function test_phase6_date_filter_reduces_contact_count(): void
    {
        $ctx = $this->seedFullDataset();
        Sanctum::actingAs($ctx['admin']);

        $all = (int) $this->getJson("/api/organizations/{$ctx['reseller']->id}/dashboard")->json('data.kpis.crm.contacts');
        $future = (int) $this->getJson("/api/organizations/{$ctx['reseller']->id}/dashboard?from=2099-01-01&to=2099-12-31")->json('data.kpis.crm.contacts');

        $this->assertGreaterThan(0, $all);
        $this->assertSame(0, $future);
    }

    public function test_phase7_kpi_accuracy_for_seeded_reseller(): void
    {
        $ctx = $this->seedFullDataset();
        Sanctum::actingAs($ctx['admin']);

        $kpis = $this->getJson("/api/organizations/{$ctx['reseller']->id}/dashboard")->assertOk()->json('data.kpis');
        $this->assertSame(1, $kpis['crm']['contacts']);
        $this->assertSame(1, $kpis['crm']['companies']);
        $this->assertSame(1, $kpis['crm']['deals']);
        $this->assertSame(1, $kpis['crm']['quotes']);
        $this->assertSame(1, $kpis['deals']['won']);
        $this->assertSame(100.0, (float) $kpis['revenue']['total_revenue']);
        $this->assertSame(8, $kpis['licenses']['available']);
        $this->assertSame(10, $kpis['licenses']['allocated']);
        $this->assertSame(2, $kpis['licenses']['consumed']);
    }

    public function test_phase8_activity_feed_contains_expected_types(): void
    {
        $ctx = $this->seedFullDataset();
        Sanctum::actingAs($ctx['admin']);

        $types = collect($this->getJson("/api/organizations/{$ctx['reseller']->id}/dashboard/activity")->assertOk()->json('data.activity.items'))
            ->pluck('type');
        $this->assertTrue($types->contains('contact_created'));
        $this->assertTrue($types->contains('deal_created'));
        $this->assertTrue($types->contains('commission_generated'));
        $this->assertTrue($types->contains('license_transfer'));
        $this->assertTrue($types->contains('resource_downloaded'));
    }

    public function test_phase9_team_lists_partner_admin_member(): void
    {
        $ctx = $this->seedFullDataset();
        $partnerAdmin = $this->makeUser($ctx['tenant']->id, Role::CODE_PARTNER_ADMIN, 'team-pa@example.com', $ctx['partner']->id);

        Sanctum::actingAs($ctx['admin']);
        $members = collect($this->getJson("/api/organizations/{$ctx['partner']->id}/dashboard/team")->assertOk()->json('data.team.members'));
        $this->assertTrue($members->pluck('email')->contains('team-pa@example.com'));
    }

    public function test_phase10_cache_differs_by_date_range(): void
    {
        Cache::flush();
        $ctx = $this->seedFullDataset();
        Sanctum::actingAs($ctx['admin']);

        $allPeriod = $this->getJson("/api/organizations/{$ctx['reseller']->id}/dashboard")->json('data.period');
        $filtered = $this->getJson("/api/organizations/{$ctx['reseller']->id}/dashboard?from=2099-01-01")->json('data.period');

        $this->assertNull($allPeriod['from']);
        $this->assertSame('2099-01-01', $filtered['from']);
    }

    public function test_phase11_partner_portal_legacy_summary_intact(): void
    {
        $ctx = $this->seedFullDataset();
        $partnerAdmin = $this->makeUser($ctx['tenant']->id, Role::CODE_PARTNER_ADMIN, 'legacy@example.com', $ctx['partner']->id);
        Sanctum::actingAs($partnerAdmin);

        $response = $this->getJson('/api/prm/partner/dashboard')->assertOk();
        $summary = $response->json('data.summary');
        $this->assertArrayHasKey('partner_organization_id', $summary);
        $this->assertArrayHasKey('counts', $summary);
        $this->assertArrayHasKey('commission_pending_total', $summary);
        $this->assertArrayHasKey('license_units_available', $summary);
        $this->assertArrayHasKey('pipeline_value', $summary);
    }

    public function test_phase11_crm_regression_contact_list_still_works(): void
    {
        $ctx = $this->seedFullDataset();
        Sanctum::actingAs($ctx['admin']);

        $this->getJson('/api/contacts')->assertOk();
        $this->getJson('/api/deals')->assertOk();
        $this->getJson('/api/organizations')->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function seedFullDataset(): array
    {
        $tenant = Tenant::query()->create(['name' => 'UAT Tenant', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'CA UAT',
            'email' => 'ca-uat-'.uniqid('', true).'@example.com',
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
        $partner = $this->makeOrg($tenant, $admin, Organization::TYPE_PARTNER, $company->id, 'UAT Partner');
        $reseller = $this->makeOrg($tenant, $admin, Organization::TYPE_RESELLER, $partner->id, 'UAT Managed', Organization::CHANNEL_MODE_PARTNER_MANAGED);
        $directReseller = $this->makeOrg($tenant, $admin, Organization::TYPE_RESELLER, $company->id, 'UAT Direct', Organization::CHANNEL_MODE_DIRECT);

        $this->seedResellerCrmBundle($tenant, $reseller, $admin, 'managed');
        $this->seedResellerCrmBundle($tenant, $directReseller, $admin, 'direct');

        Contact::query()->create([
            'tenant_id' => $tenant->id,
            'channel_organization_id' => $partner->id,
            'first_name' => 'Partner',
            'email' => 'partner-only@example.com',
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);

        CommissionAccrual::query()->create([
            'tenant_id' => $tenant->id,
            'partner_organization_id' => $partner->id,
            'base_amount' => 100,
            'commission_amount' => 10,
            'currency_code' => 'ZAR',
            'status' => CommissionAccrual::STATUS_PENDING,
        ]);

        return compact('tenant', 'admin', 'company', 'partner', 'reseller', 'directReseller');
    }

    private function seedResellerCrmBundle(Tenant $tenant, Organization $reseller, User $admin, string $suffix): void
    {
        Company::query()->create([
            'tenant_id' => $tenant->id,
            'channel_organization_id' => $reseller->id,
            'name' => "Co {$suffix}",
            'email' => "co-{$suffix}@example.com",
            'created_by_user_id' => $admin->id,
        ]);
        $pipeline = $this->seedPipeline($tenant, $admin);
        $stageId = (int) PipelineStage::query()->where('pipeline_id', $pipeline->id)->value('id');
        $contact = Contact::query()->create([
            'tenant_id' => $tenant->id,
            'channel_organization_id' => $reseller->id,
            'first_name' => ucfirst($suffix),
            'email' => "contact-{$suffix}@example.com",
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);
        $deal = Deal::query()->create([
            'tenant_id' => $tenant->id,
            'channel_organization_id' => $reseller->id,
            'partner_organization_id' => $reseller->id,
            'name' => "Deal {$suffix}",
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
            'public_uuid' => (string) Str::uuid(),
            'quote_number' => "Q-{$suffix}",
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
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'name' => "Product {$suffix}",
            'sku' => "SKU-{$suffix}",
            'unit_price' => 10,
            'tax_rate' => 0,
            'status' => Product::STATUS_ACTIVE,
        ]);
        $entitlement = LicenseEntitlement::query()->create([
            'tenant_id' => $tenant->id,
            'holder_organization_id' => $reseller->id,
            'product_id' => $product->id,
            'units_total' => 10,
            'units_consumed' => 2,
            'created_by_user_id' => $admin->id,
        ]);
        LicenseMovement::query()->create([
            'tenant_id' => $tenant->id,
            'to_organization_id' => $reseller->id,
            'movement_type' => LicenseMovement::TYPE_TRANSFER,
            'units' => 2,
            'actor_user_id' => $admin->id,
        ]);
        LicenseActivation::query()->create([
            'tenant_id' => $tenant->id,
            'license_entitlement_id' => $entitlement->id,
            'units' => 1,
            'activated_by_user_id' => $admin->id,
        ]);
        CommissionAccrual::query()->create([
            'tenant_id' => $tenant->id,
            'partner_organization_id' => $reseller->id,
            'base_amount' => 50,
            'commission_amount' => 5,
            'currency_code' => 'ZAR',
            'status' => CommissionAccrual::STATUS_PENDING,
        ]);
        $collateral = Collateral::query()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'name' => "Collateral {$suffix}",
            'description' => null,
            'type' => 'brochure',
            'file_key' => "tenant/{$tenant->id}/collaterals/{$suffix}.pdf",
            'file_type' => 'application/pdf',
            'file_size' => 100,
            'partner_visible' => true,
            'reseller_visible' => true,
            'resource_category' => 'brochure',
            'status' => Collateral::STATUS_ACTIVE,
            'metadata' => null,
        ]);
        CollateralDownload::query()->create([
            'tenant_id' => $tenant->id,
            'collateral_id' => $collateral->id,
            'user_id' => $admin->id,
            'partner_organization_id' => $reseller->id,
            'downloaded_at' => now(),
        ]);
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
