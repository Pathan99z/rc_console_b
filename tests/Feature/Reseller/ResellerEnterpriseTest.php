<?php

namespace Tests\Feature\Reseller;

use App\Models\CommissionAccrual;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\LicenseEntitlement;
use App\Models\LicenseMovement;
use App\Models\Organization;
use App\Models\PartnerProgram;
use App\Models\PartnerProgramEnrollment;
use App\Models\PaymentRecord;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserOrganizationAssignment;
use App\Services\Prm\CommissionAccrualService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResellerEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_reseller_organization_blocks_crm_access(): void
    {
        [$tenant, $companyAdmin, $company, $reseller] = $this->seedDirectReseller(pending: true);
        $user = $this->makeChannelUser($tenant, Role::CODE_RESELLER_ADMIN, 'pending-reseller@example.com', $reseller->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/contacts')->assertStatus(403);
    }

    public function test_active_reseller_sees_only_own_organization_in_list(): void
    {
        [$tenant, $companyAdmin, $company, $reseller] = $this->seedDirectReseller();
        $sibling = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_RESELLER,
            'channel_mode' => Organization::CHANNEL_MODE_DIRECT,
            'legal_name' => 'Sibling Legal',
            'display_name' => 'Sibling',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        $user = $this->makeChannelUser($tenant, Role::CODE_RESELLER_ADMIN, 'reseller-a@example.com', $reseller->id);
        Sanctum::actingAs($user);

        $ids = collect($this->getJson('/api/organizations')->assertOk()->json('data.items'))->pluck('id');
        $this->assertTrue($ids->contains($reseller->id));
        $this->assertFalse($ids->contains($sibling->id));
    }

    public function test_reseller_admin_can_invite_sales_consultant_via_org_users_endpoint(): void
    {
        [$tenant, , , $reseller] = $this->seedDirectReseller();
        $admin = $this->makeChannelUser($tenant, Role::CODE_RESELLER_ADMIN, 'reseller-admin-invite@example.com', $reseller->id);
        Sanctum::actingAs($admin);

        $this->postJson("/api/organizations/{$reseller->id}/users/invite", [
            'email' => 'consultant@example.com',
            'role_code' => Role::CODE_RESELLER_SALES_CONSULTANT,
        ])->assertCreated();
    }

    public function test_direct_reseller_commission_uses_own_enrollment(): void
    {
        [$tenant, $companyAdmin, $company, $reseller] = $this->seedDirectReseller();
        $program = PartnerProgram::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'direct-tier',
            'name' => 'Direct Tier',
            'tier_level' => 1,
            'default_commission_percent' => 12.5,
            'status' => PartnerProgram::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($companyAdmin);
        $this->postJson('/api/prm/programs/enroll', [
            'organization_id' => $reseller->id,
            'partner_program_id' => $program->id,
        ])->assertCreated();

        $pipeline = $this->seedPipeline($tenant, $companyAdmin);
        $stage = PipelineStage::query()->where('pipeline_id', $pipeline->id)->first();
        $contact = Contact::query()->create([
            'tenant_id' => $tenant->id,
            'channel_organization_id' => $reseller->id,
            'first_name' => 'Buyer',
            'email' => 'buyer-dr@example.com',
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);
        $deal = Deal::query()->create([
            'tenant_id' => $tenant->id,
            'channel_organization_id' => $reseller->id,
            'partner_organization_id' => $reseller->id,
            'name' => 'Reseller Deal',
            'contact_id' => $contact->id,
            'owner_user_id' => $companyAdmin->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $stage->id,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'status' => Deal::STATUS_OPEN,
        ]);

        $quote = Quote::query()->create([
            'tenant_id' => $tenant->id,
            'channel_organization_id' => $reseller->id,
            'deal_id' => $deal->id,
            'contact_id' => $contact->id,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'quote_number' => 'Q-DR-1',
            'public_uuid' => 'uuid-dr-1',
            'status' => Quote::STATUS_SENT,
            'payment_status' => Quote::PAYMENT_STATUS_PAID,
            'subtotal' => 1000,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 1000,
            'currency_code' => 'USD',
        ]);

        $payment = PaymentRecord::query()->create([
            'tenant_id' => $tenant->id,
            'quote_id' => $quote->id,
            'amount' => 1000,
            'currency_code' => 'USD',
            'status' => PaymentRecord::STATUS_SUCCESS,
            'transaction_id' => 'ref-dr-1',
        ]);

        app(CommissionAccrualService::class)->processSuccessfulPayment($quote, $payment);

        $accrual = CommissionAccrual::query()->where('quote_id', $quote->id)->first();
        $this->assertNotNull($accrual);
        $this->assertSame($reseller->id, (int) $accrual->partner_organization_id);
        $this->assertSame(125.0, (float) $accrual->commission_amount);
        $this->assertSame('reseller_direct', $accrual->rule_snapshot['resolution_mode'] ?? null);
    }

    public function test_partner_managed_reseller_inherits_partner_commission(): void
    {
        [$tenant, $companyAdmin, $company, $partner, $reseller] = $this->seedPartnerManagedReseller();
        $program = PartnerProgram::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'partner-tier',
            'name' => 'Partner Tier',
            'tier_level' => 1,
            'default_commission_percent' => 10.0,
            'status' => PartnerProgram::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($companyAdmin);
        $this->postJson('/api/prm/programs/enroll', [
            'organization_id' => $partner->id,
            'partner_program_id' => $program->id,
        ])->assertCreated();

        $pipeline = $this->seedPipeline($tenant, $companyAdmin);
        $stage = PipelineStage::query()->where('pipeline_id', $pipeline->id)->first();
        $contact = Contact::query()->create([
            'tenant_id' => $tenant->id,
            'channel_organization_id' => $reseller->id,
            'first_name' => 'Buyer',
            'email' => 'buyer-inh@example.com',
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);
        $deal = Deal::query()->create([
            'tenant_id' => $tenant->id,
            'channel_organization_id' => $reseller->id,
            'partner_organization_id' => $reseller->id,
            'name' => 'Inherited Deal',
            'contact_id' => $contact->id,
            'owner_user_id' => $companyAdmin->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $stage->id,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'status' => Deal::STATUS_OPEN,
        ]);

        $quote = Quote::query()->create([
            'tenant_id' => $tenant->id,
            'channel_organization_id' => $reseller->id,
            'deal_id' => $deal->id,
            'contact_id' => $contact->id,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'quote_number' => 'Q-INH-1',
            'public_uuid' => 'uuid-inh-1',
            'status' => Quote::STATUS_SENT,
            'payment_status' => Quote::PAYMENT_STATUS_PAID,
            'subtotal' => 500,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 500,
            'currency_code' => 'USD',
        ]);

        $payment = PaymentRecord::query()->create([
            'tenant_id' => $tenant->id,
            'quote_id' => $quote->id,
            'amount' => 500,
            'currency_code' => 'USD',
            'status' => PaymentRecord::STATUS_SUCCESS,
            'transaction_id' => 'ref-inh-1',
        ]);

        app(CommissionAccrualService::class)->processSuccessfulPayment($quote, $payment);

        $accrual = CommissionAccrual::query()->where('quote_id', $quote->id)->first();
        $this->assertNotNull($accrual);
        $this->assertSame($reseller->id, (int) $accrual->partner_organization_id);
        $this->assertSame(50.0, (float) $accrual->commission_amount);
        $this->assertSame('reseller_inherit_partner', $accrual->rule_snapshot['resolution_mode'] ?? null);
    }

    public function test_license_transfer_creates_child_entitlement_and_movement(): void
    {
        [$tenant, $companyAdmin, , $partner, $reseller] = $this->seedPartnerManagedReseller();
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'name' => 'Lic Prod',
            'sku' => 'LIC-1',
            'unit_price' => 10,
            'tax_rate' => 0,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $parent = LicenseEntitlement::query()->create([
            'tenant_id' => $tenant->id,
            'holder_organization_id' => $partner->id,
            'units_total' => 20,
            'units_consumed' => 0,
            'product_id' => $product->id,
            'created_by_user_id' => $companyAdmin->id,
        ]);

        $partnerAdmin = $this->makeChannelUser($tenant, Role::CODE_PARTNER_ADMIN, 'partner-lic@example.com', $partner->id);
        Sanctum::actingAs($partnerAdmin);

        $this->postJson('/api/prm/license-entitlements/transfer', [
            'from_entitlement_id' => $parent->id,
            'to_organization_id' => $reseller->id,
            'units' => 5,
        ])->assertCreated();

        $this->assertDatabaseHas('license_entitlements', [
            'holder_organization_id' => $reseller->id,
            'parent_entitlement_id' => $parent->id,
            'units_total' => 5,
        ]);
        $this->assertDatabaseHas('license_movements', [
            'from_entitlement_id' => $parent->id,
            'to_organization_id' => $reseller->id,
            'movement_type' => LicenseMovement::TYPE_TRANSFER,
            'units' => 5,
        ]);
        $parent->refresh();
        $this->assertSame(5, (int) $parent->units_consumed);
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Organization, 3: Organization}
     */
    private function seedDirectReseller(bool $pending = false): array
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant R', 'status' => Tenant::STATUS_ACTIVE]);
        $companyAdmin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'CA',
            'email' => 'ca-'.uniqid('', true).'@example.com',
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
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $reseller = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_RESELLER,
            'channel_mode' => Organization::CHANNEL_MODE_DIRECT,
            'legal_name' => 'Direct R Legal',
            'display_name' => 'Direct R',
            'onboarding_status' => $pending ? Organization::ONBOARDING_PENDING_REVIEW : Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        return [$tenant, $companyAdmin, $company, $reseller];
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Organization, 3: Organization, 4: Organization}
     */
    private function seedPartnerManagedReseller(): array
    {
        [$tenant, $companyAdmin, $company] = $this->seedDirectReseller();
        $partner = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner Legal',
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
            'legal_name' => 'Managed R Legal',
            'display_name' => 'Managed R',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        return [$tenant, $companyAdmin, $company, $partner, $reseller];
    }

    private function makeChannelUser(Tenant $tenant, string $role, string $email, int $organizationId): User
    {
        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'User',
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
            'name' => 'Default',
            'status' => Pipeline::STATUS_ACTIVE,
        ]);
        PipelineStage::query()->create([
            'tenant_id' => $tenant->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Stage',
            'stage_order' => 1,
            'status' => PipelineStage::STATUS_ACTIVE,
        ]);

        return $pipeline;
    }
}
