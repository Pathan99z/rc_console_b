<?php

namespace Tests\Feature\Prm;

use App\Models\CommissionAccrual;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\PaymentRecord;
use App\Models\Payout;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserOrganizationAssignment;
use App\Services\Prm\CommissionAccrualService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayoutSettlementFullUatTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_payout_lifecycle_for_direct_reseller(): void
    {
        Storage::fake('local');
        $ctx = $this->seedResellerWithApprovedCommission();
        Sanctum::actingAs($ctx['admin']);

        $generate = $this->postJson('/api/prm/payouts/generate', [
            'beneficiary_organization_id' => $ctx['reseller']->id,
        ])->assertCreated();

        $payoutId = (int) $generate->json('data.payouts.0.id');
        $this->assertSame('draft', $generate->json('data.payouts.0.status'));

        $this->postJson("/api/prm/payouts/{$payoutId}/submit")->assertOk();
        $this->postJson("/api/prm/payouts/{$payoutId}/approve")->assertOk();
        $this->postJson("/api/prm/payouts/{$payoutId}/process")->assertOk();

        $this->postJson("/api/prm/payouts/{$payoutId}/mark-paid", [
            'payment_method' => 'neft',
            'remittance_reference' => 'UTR123456',
            'payment_date' => '2026-05-15',
            'remarks' => 'Manual settlement',
            'supporting_document' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
        ])->assertOk()->assertJsonPath('data.payout.status', Payout::STATUS_PAID);

        $accrual = CommissionAccrual::query()->find($ctx['accrual']->id);
        $this->assertSame(CommissionAccrual::STATUS_PAID, $accrual->status);

        $this->getJson("/api/prm/payouts/{$payoutId}/statement")->assertOk()
            ->assertJsonStructure(['data' => ['statement' => ['payout', 'line_items']]]);
    }

    public function test_partner_admin_can_view_own_payout_not_sibling(): void
    {
        $ctx = $this->seedResellerWithApprovedCommission();
        $partnerAdmin = $this->makeUser($ctx['tenant']->id, Role::CODE_PARTNER_ADMIN, 'pa-pay@example.com', $ctx['partner']->id);
        $otherPartner = Organization::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'parent_organization_id' => $ctx['company']->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Other',
            'display_name' => 'Other',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $ctx['admin']->id,
            'updated_by_user_id' => $ctx['admin']->id,
        ]);

        Sanctum::actingAs($ctx['admin']);
        $payoutId = (int) $this->postJson('/api/prm/payouts/generate', [
            'beneficiary_organization_id' => $ctx['reseller']->id,
        ])->json('data.payouts.0.id');

        Sanctum::actingAs($partnerAdmin);
        $this->getJson("/api/prm/payouts/{$payoutId}")->assertOk();
        $this->getJson('/api/prm/partner/payouts')->assertOk();

        CommissionAccrual::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'partner_organization_id' => $otherPartner->id,
            'base_amount' => 50,
            'commission_amount' => 5,
            'currency_code' => 'ZAR',
            'status' => CommissionAccrual::STATUS_APPROVED,
        ]);
        Sanctum::actingAs($ctx['admin']);
        $otherPayoutId = (int) $this->postJson('/api/prm/payouts/generate', [
            'beneficiary_organization_id' => $otherPartner->id,
        ])->json('data.payouts.0.id');

        Sanctum::actingAs($partnerAdmin);
        $this->getJson("/api/prm/payouts/{$otherPayoutId}")->assertNotFound();
    }

    public function test_failed_payout_releases_accrual_for_retry(): void
    {
        $ctx = $this->seedResellerWithApprovedCommission();
        Sanctum::actingAs($ctx['admin']);

        $payoutId = (int) $this->postJson('/api/prm/payouts/generate', [
            'beneficiary_organization_id' => $ctx['reseller']->id,
        ])->json('data.payouts.0.id');

        $this->postJson("/api/prm/payouts/{$payoutId}/submit")->assertOk();
        $this->postJson("/api/prm/payouts/{$payoutId}/approve")->assertOk();
        $this->postJson("/api/prm/payouts/{$payoutId}/process")->assertOk();
        $this->postJson("/api/prm/payouts/{$payoutId}/fail", [
            'failure_reason' => 'Bank rejected',
            'remarks' => 'Retry next cycle',
        ])->assertOk();

        $this->postJson('/api/prm/payouts/generate', [
            'beneficiary_organization_id' => $ctx['reseller']->id,
        ])->assertCreated();
    }

    public function test_commission_accruals_list_includes_display_details(): void
    {
        $ctx = $this->seedResellerWithApprovedCommission();
        $contact = Contact::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'channel_organization_id' => $ctx['reseller']->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane-'.uniqid('', true).'@example.com',
            'created_by_user_id' => $ctx['admin']->id,
            'updated_by_user_id' => $ctx['admin']->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);
        $pipeline = Pipeline::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'created_by_user_id' => $ctx['admin']->id,
            'name' => 'Sales',
            'status' => 'active',
        ]);
        $stage = PipelineStage::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Won',
            'stage_order' => 1,
            'status' => 'active',
        ]);
        $deal = Deal::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'channel_organization_id' => $ctx['reseller']->id,
            'partner_organization_id' => $ctx['reseller']->id,
            'contact_id' => $contact->id,
            'owner_user_id' => $ctx['admin']->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $stage->id,
            'created_by_user_id' => $ctx['admin']->id,
            'updated_by_user_id' => $ctx['admin']->id,
            'name' => 'Enterprise Deal',
            'status' => Deal::STATUS_WON,
        ]);
        $quote = Quote::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'channel_organization_id' => $ctx['reseller']->id,
            'deal_id' => $deal->id,
            'contact_id' => $contact->id,
            'created_by_user_id' => $ctx['admin']->id,
            'updated_by_user_id' => $ctx['admin']->id,
            'quote_number' => 'Q-'.uniqid('', true),
            'public_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'status' => Quote::STATUS_ACCEPTED,
            'payment_status' => Quote::PAYMENT_STATUS_PAID,
            'total' => 1000,
            'currency_code' => 'ZAR',
        ]);
        $payment = PaymentRecord::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'quote_id' => $quote->id,
            'amount' => 1000,
            'currency_code' => 'ZAR',
            'status' => 'success',
            'transaction_id' => 'TXN-123',
        ]);
        $ctx['accrual']->update([
            'quote_id' => $quote->id,
            'payment_record_id' => $payment->id,
        ]);

        Sanctum::actingAs($ctx['admin']);
        $item = $this->getJson('/api/prm/commission-accruals')
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame($ctx['accrual']->id, $item['id']);
        $this->assertEquals(100.0, $item['amount']);
        $this->assertSame('Reseller', $item['partner_organization']['name']);
        $this->assertSame($quote->quote_number, $item['quote']['quote_number']);
        $this->assertSame('Enterprise Deal', $item['quote']['deal_name']);
        $this->assertSame('TXN-123', $item['payment_record']['transaction_id']);
        $this->assertTrue($item['available_for_payout']);
        $this->assertFalse($item['in_payout']);
        $this->assertNotEmpty($item['summary']);
    }

    public function test_commission_idempotency_on_payment_record(): void
    {
        $ctx = $this->seedResellerWithApprovedCommission();
        $pipeline = \App\Models\Pipeline::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'created_by_user_id' => $ctx['admin']->id,
            'name' => 'P',
            'status' => \App\Models\Pipeline::STATUS_ACTIVE,
        ]);
        $stageId = (int) \App\Models\PipelineStage::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'S',
            'stage_order' => 1,
            'status' => \App\Models\PipelineStage::STATUS_ACTIVE,
        ])->id;
        $contact = \App\Models\Contact::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'channel_organization_id' => $ctx['reseller']->id,
            'first_name' => 'C',
            'email' => 'c-idem@example.com',
            'created_by_user_id' => $ctx['admin']->id,
            'updated_by_user_id' => $ctx['admin']->id,
            'lifecycle_stage' => \App\Models\Contact::STAGE_LEAD,
        ]);
        $deal = \App\Models\Deal::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'channel_organization_id' => $ctx['reseller']->id,
            'contact_id' => $contact->id,
            'name' => 'D',
            'owner_user_id' => $ctx['admin']->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $stageId,
            'created_by_user_id' => $ctx['admin']->id,
            'updated_by_user_id' => $ctx['admin']->id,
            'status' => \App\Models\Deal::STATUS_OPEN,
        ]);
        $quote = \App\Models\Quote::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'channel_organization_id' => $ctx['reseller']->id,
            'deal_id' => $deal->id,
            'contact_id' => $contact->id,
            'created_by_user_id' => $ctx['admin']->id,
            'updated_by_user_id' => $ctx['admin']->id,
            'quote_number' => 'Q-IDEM',
            'public_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'status' => \App\Models\Quote::STATUS_SENT,
            'payment_status' => \App\Models\Quote::PAYMENT_STATUS_PAID,
            'subtotal' => 100,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 100,
            'currency_code' => 'ZAR',
        ]);
        $payment = \App\Models\PaymentRecord::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'quote_id' => $quote->id,
            'amount' => 100,
            'currency_code' => 'ZAR',
            'status' => \App\Models\PaymentRecord::STATUS_SUCCESS,
        ]);

        CommissionAccrual::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'partner_organization_id' => $ctx['reseller']->id,
            'payment_record_id' => $payment->id,
            'base_amount' => 100,
            'commission_amount' => 10,
            'currency_code' => 'ZAR',
            'status' => CommissionAccrual::STATUS_PENDING,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        CommissionAccrual::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'partner_organization_id' => $ctx['reseller']->id,
            'payment_record_id' => $payment->id,
            'base_amount' => 100,
            'commission_amount' => 10,
            'currency_code' => 'ZAR',
            'status' => CommissionAccrual::STATUS_PENDING,
        ]);
    }

    public function test_consultant_cannot_access_payouts(): void
    {
        $ctx = $this->seedResellerWithApprovedCommission();
        $consultant = $this->makeUser($ctx['tenant']->id, Role::CODE_RESELLER_SALES_CONSULTANT, 'con@example.com', $ctx['reseller']->id);

        Sanctum::actingAs($consultant);
        $this->getJson('/api/prm/payouts')->assertForbidden();
    }

    public function test_dashboard_includes_payout_metrics(): void
    {
        $ctx = $this->seedResellerWithApprovedCommission();
        Sanctum::actingAs($ctx['admin']);

        $this->getJson("/api/organizations/{$ctx['reseller']->id}/dashboard/payouts")->assertOk()
            ->assertJsonStructure(['data' => ['payouts' => ['commission_liability_approved']]]);
    }

    public function test_crm_regression_still_works(): void
    {
        $ctx = $this->seedResellerWithApprovedCommission();
        Sanctum::actingAs($ctx['admin']);

        $this->getJson('/api/contacts')->assertOk();
        $this->getJson('/api/deals')->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function seedResellerWithApprovedCommission(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Pay Tenant', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'CA',
            'email' => 'ca-pay-'.uniqid('', true).'@example.com',
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
        $partner = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner',
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
            'legal_name' => 'Reseller',
            'display_name' => 'Reseller',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        $accrual = CommissionAccrual::query()->create([
            'tenant_id' => $tenant->id,
            'partner_organization_id' => $reseller->id,
            'base_amount' => 1000,
            'commission_amount' => 100,
            'currency_code' => 'ZAR',
            'status' => CommissionAccrual::STATUS_APPROVED,
        ]);

        return compact('tenant', 'admin', 'company', 'partner', 'reseller', 'accrual');
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
}
