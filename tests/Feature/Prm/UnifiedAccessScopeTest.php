<?php

namespace Tests\Feature\Prm;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserOrganizationAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnifiedAccessScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_payload_and_navigation_are_backward_compatible_and_enriched(): void
    {
        [$tenant, $partnerOrg] = $this->tenantWithPartnerHierarchy();
        $partner = $this->makeUser($tenant, 'partner_admin', 'partner-a@example.com');
        UserOrganizationAssignment::query()->create(['user_id' => $partner->id, 'organization_id' => $partnerOrg->id]);
        Sanctum::actingAs($partner);

        $userResponse = $this->getJson('/api/user')->assertOk();
        $userResponse->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'user' => [
                    'id',
                    'role',
                    'roles',
                    'permissions',
                    'organization',
                    'navigation_profile',
                    'feature_flags',
                ],
            ],
        ]);

        $this->getJson('/api/navigation')
            ->assertOk()
            ->assertJsonPath('data.navigation_profile', 'partner_admin')
            ->assertJsonStructure(['data' => ['menus' => ['crm', 'prm'], 'feature_flags']]);
    }

    public function test_partner_company_scope_prevents_cross_org_leakage(): void
    {
        [$tenant, $partnerA, $partnerB] = $this->tenantWithTwoPartners();
        $partnerUser = $this->makeUser($tenant, 'partner_admin', 'partner-scope@example.com');
        $partnerBUser = $this->makeUser($tenant, 'partner_admin', 'partner-b-scope@example.com');
        UserOrganizationAssignment::query()->create(['user_id' => $partnerUser->id, 'organization_id' => $partnerA->id]);
        UserOrganizationAssignment::query()->create(['user_id' => $partnerBUser->id, 'organization_id' => $partnerB->id]);

        $pipeline = Pipeline::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $partnerUser->id,
            'name' => 'Default',
            'status' => Pipeline::STATUS_ACTIVE,
        ]);
        $stage = PipelineStage::query()->create([
            'tenant_id' => $tenant->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Prospect',
            'stage_order' => 1,
            'status' => PipelineStage::STATUS_ACTIVE,
        ]);

        $companyA = Company::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Partner A Company',
            'created_by_user_id' => $partnerUser->id,
            'assigned_user_id' => $partnerUser->id,
            'status' => Company::STATUS_ACTIVE,
        ]);
        $companyB = Company::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Partner B Company',
            'created_by_user_id' => $partnerBUser->id,
            'assigned_user_id' => $partnerBUser->id,
            'status' => Company::STATUS_ACTIVE,
        ]);
        $contactA = Contact::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'A',
            'email' => 'a-contact@example.com',
            'company_id' => $companyA->id,
            'created_by_user_id' => $partnerUser->id,
            'updated_by_user_id' => $partnerUser->id,
            'assigned_user_id' => $partnerUser->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);
        $contactB = Contact::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'B',
            'email' => 'b-contact@example.com',
            'company_id' => $companyB->id,
            'created_by_user_id' => $partnerBUser->id,
            'updated_by_user_id' => $partnerBUser->id,
            'assigned_user_id' => $partnerBUser->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);

        Deal::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'A Deal',
            'contact_id' => $contactA->id,
            'company_id' => $companyA->id,
            'owner_user_id' => $partnerUser->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $stage->id,
            'partner_organization_id' => $partnerA->id,
            'created_by_user_id' => $partnerUser->id,
            'updated_by_user_id' => $partnerUser->id,
            'status' => Deal::STATUS_OPEN,
        ]);
        Deal::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'B Deal',
            'contact_id' => $contactB->id,
            'company_id' => $companyB->id,
            'owner_user_id' => $partnerBUser->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $stage->id,
            'partner_organization_id' => $partnerB->id,
            'created_by_user_id' => $partnerBUser->id,
            'updated_by_user_id' => $partnerBUser->id,
            'status' => Deal::STATUS_OPEN,
        ]);

        Sanctum::actingAs($partnerUser);

        $response = $this->getJson('/api/companies')->assertOk();
        $items = collect($response->json('data.items'));
        $this->assertTrue($items->pluck('id')->contains($companyA->id));
        $this->assertFalse($items->pluck('id')->contains($companyB->id));
    }

    public function test_partner_invoice_scope_is_constrained_to_channel_visibility(): void
    {
        [$tenant, $partnerA, $partnerB] = $this->tenantWithTwoPartners();
        $partnerUser = $this->makeUser($tenant, 'partner_admin', 'partner-invoice@example.com');
        $partnerBUser = $this->makeUser($tenant, 'partner_admin', 'partner-b-invoice@example.com');
        UserOrganizationAssignment::query()->create(['user_id' => $partnerUser->id, 'organization_id' => $partnerA->id]);
        UserOrganizationAssignment::query()->create(['user_id' => $partnerBUser->id, 'organization_id' => $partnerB->id]);

        $pipeline = Pipeline::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $partnerUser->id,
            'name' => 'Default',
            'status' => Pipeline::STATUS_ACTIVE,
        ]);
        $stage = PipelineStage::query()->create([
            'tenant_id' => $tenant->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Prospect',
            'stage_order' => 1,
            'status' => PipelineStage::STATUS_ACTIVE,
        ]);

        $companyA = Company::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'A Co',
            'created_by_user_id' => $partnerUser->id,
            'assigned_user_id' => $partnerUser->id,
            'status' => Company::STATUS_ACTIVE,
        ]);
        $contactA = Contact::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'A',
            'email' => 'inv-a-contact@example.com',
            'company_id' => $companyA->id,
            'created_by_user_id' => $partnerUser->id,
            'updated_by_user_id' => $partnerUser->id,
            'assigned_user_id' => $partnerUser->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);
        $dealA = Deal::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'A Deal',
            'contact_id' => $contactA->id,
            'company_id' => $companyA->id,
            'owner_user_id' => $partnerUser->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $stage->id,
            'partner_organization_id' => $partnerA->id,
            'created_by_user_id' => $partnerUser->id,
            'updated_by_user_id' => $partnerUser->id,
            'status' => Deal::STATUS_OPEN,
        ]);

        $companyB = Company::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'B Co',
            'created_by_user_id' => $partnerBUser->id,
            'assigned_user_id' => $partnerBUser->id,
            'status' => Company::STATUS_ACTIVE,
        ]);
        $contactB = Contact::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'B',
            'email' => 'inv-b-contact@example.com',
            'company_id' => $companyB->id,
            'created_by_user_id' => $partnerBUser->id,
            'updated_by_user_id' => $partnerBUser->id,
            'assigned_user_id' => $partnerBUser->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);
        $dealB = Deal::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'B Deal',
            'contact_id' => $contactB->id,
            'company_id' => $companyB->id,
            'owner_user_id' => $partnerBUser->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $stage->id,
            'partner_organization_id' => $partnerB->id,
            'created_by_user_id' => $partnerBUser->id,
            'updated_by_user_id' => $partnerBUser->id,
            'status' => Deal::STATUS_OPEN,
        ]);

        $quoteA = Quote::query()->create([
            'tenant_id' => $tenant->id,
            'deal_id' => $dealA->id,
            'contact_id' => $contactA->id,
            'created_by_user_id' => $partnerUser->id,
            'updated_by_user_id' => $partnerUser->id,
            'quote_number' => 'Q-A',
            'public_uuid' => 'q-a-uuid',
            'status' => Quote::STATUS_SENT,
            'payment_status' => Quote::PAYMENT_STATUS_PAID,
            'subtotal' => 100,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 100,
            'currency_code' => 'USD',
        ]);
        $quoteB = Quote::query()->create([
            'tenant_id' => $tenant->id,
            'deal_id' => $dealB->id,
            'contact_id' => $contactB->id,
            'created_by_user_id' => $partnerBUser->id,
            'updated_by_user_id' => $partnerBUser->id,
            'quote_number' => 'Q-B',
            'public_uuid' => 'q-b-uuid',
            'status' => Quote::STATUS_SENT,
            'payment_status' => Quote::PAYMENT_STATUS_PAID,
            'subtotal' => 100,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 100,
            'currency_code' => 'USD',
        ]);

        $invoiceA = Invoice::query()->create([
            'tenant_id' => $tenant->id,
            'quote_id' => $quoteA->id,
            'invoice_number' => 'INV-A',
            'status' => Invoice::STATUS_PAID,
            'customer_name' => 'A Customer',
            'subtotal' => 100,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 100,
            'currency_code' => 'USD',
        ]);
        Invoice::query()->create([
            'tenant_id' => $tenant->id,
            'quote_id' => $quoteB->id,
            'invoice_number' => 'INV-B',
            'status' => Invoice::STATUS_PAID,
            'customer_name' => 'B Customer',
            'subtotal' => 100,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 100,
            'currency_code' => 'USD',
        ]);

        Sanctum::actingAs($partnerUser);
        $list = $this->getJson('/api/invoices')->assertOk();
        $ids = collect($list->json('data.items'))->pluck('id')->all();
        $this->assertSame([$invoiceA->id], $ids);
    }

    public function test_deprecated_partner_lead_endpoint_still_works(): void
    {
        [$tenant, $partnerOrg] = $this->tenantWithPartnerHierarchy();
        $partner = $this->makeUser($tenant, 'partner_admin', 'partner-lead@example.com');
        UserOrganizationAssignment::query()->create(['user_id' => $partner->id, 'organization_id' => $partnerOrg->id]);
        Sanctum::actingAs($partner);

        $response = $this->postJson('/api/prm/partner/leads', [
            'title' => 'New channel lead',
            'contact_email' => 'lead-map@example.com',
            'contact_first_name' => 'Lead',
            'company_name' => 'Lead Co',
            'status' => 'new',
            'metadata' => ['source' => 'qa'],
        ])->assertCreated();

        $this->assertNotNull($response->json('data.lead.id'));
    }

    /**
     * @return array{Tenant, Organization}
     */
    private function tenantWithPartnerHierarchy(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant U', 'status' => Tenant::STATUS_ACTIVE]);
        $companyAdmin = $this->makeUser($tenant, 'company_admin', 'company-admin@example.com');
        $rootCompany = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Root Co',
            'display_name' => 'Root Co',
            'status' => Organization::STATUS_ACTIVE,
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $partnerOrg = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $rootCompany->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner Co',
            'display_name' => 'Partner Co',
            'status' => Organization::STATUS_ACTIVE,
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        return [$tenant, $partnerOrg];
    }

    /**
     * @return array{Tenant, Organization, Organization}
     */
    private function tenantWithTwoPartners(): array
    {
        [$tenant, $partnerA] = $this->tenantWithPartnerHierarchy();
        $companyAdmin = $this->makeUser($tenant, 'company_admin', 'company-admin-two@example.com');
        $partnerB = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner B',
            'display_name' => 'Partner B',
            'status' => Organization::STATUS_ACTIVE,
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        return [$tenant, $partnerA, $partnerB];
    }

    private function makeUser(Tenant $tenant, string $role, string $email): User
    {
        return User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'User '.str_replace('@example.com', '', $email),
            'email' => $email,
            'password' => 'secret123',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
    }
}
