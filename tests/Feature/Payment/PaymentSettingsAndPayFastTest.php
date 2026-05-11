<?php

namespace Tests\Feature\Payment;

use App\Models\PaymentRecord;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Payment\PayFastPaymentStatus;
use App\Support\Payment\PayFastSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentSettingsAndPayFastTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_cannot_access_payment_settings(): void
    {
        [$tenant, $admin] = $this->createCompanyAdmin();
        $user = $this->createStandardUser($tenant);
        Sanctum::actingAs($user);

        $this->getJson('/api/settings/payment')->assertForbidden();
    }

    public function test_company_admin_can_save_and_fetch_masked_settings(): void
    {
        [, $admin] = $this->createCompanyAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/settings/payment', [
            'payfast_mode' => 'sandbox',
            'merchant_id' => '10000100',
            'merchant_key' => 'secretmerchant',
            'passphrase' => 'pp-secret',
            'return_url' => 'https://example.com/ok',
            'cancel_url' => 'https://example.com/cancel',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.passphrase_configured', true);

        $this->getJson('/api/settings/payment')
            ->assertOk()
            ->assertJsonPath('data.merchant_id', '10000100')
            ->assertJsonPath('data.passphrase_configured', true);
    }

    public function test_payfast_webhook_invalid_signature_is_rejected(): void
    {
        config([
            'payfast.fallback_merchant_id' => '100',
            'payfast.fallback_merchant_key' => 'k',
            'payfast.fallback_passphrase' => 'sec',
        ]);

        [$tenant, $admin] = $this->createCompanyAdmin();
        $quote = $this->createSentQuote($tenant, $admin);
        $record = PaymentRecord::query()->create([
            'tenant_id' => $tenant->id,
            'quote_id' => $quote->id,
            'amount' => $quote->total,
            'currency_code' => 'ZAR',
            'status' => PaymentRecord::STATUS_PENDING,
        ]);

        $this->post('/api/payments/webhook/payfast', [
            'm_payment_id' => (string) $record->id,
            'merchant_id' => '100',
            'amount_gross' => (string) $quote->total,
            'payment_status' => PayFastPaymentStatus::COMPLETE,
            'pf_payment_id' => 'PF1',
            'signature' => 'invalid',
        ])->assertStatus(400);
    }

    public function test_payfast_webhook_completes_payment_when_signature_valid(): void
    {
        [$tenant, $admin] = $this->createCompanyAdmin();
        Sanctum::actingAs($admin);
        $this->postJson('/api/settings/payment', [
            'payfast_mode' => 'sandbox',
            'merchant_id' => '10000100',
            'merchant_key' => 'secretmerchant',
            'passphrase' => 'pp-secret',
        ])->assertCreated();

        $quote = $this->createSentQuote($tenant, $admin);

        Sanctum::actingAs($admin);
        $link = $this->postJson("/api/quotes/{$quote->id}/payment-link")->assertOk()->json('data');
        $recordId = (int) $link['payment_record_id'];
        $fields = $link['fields'];

        $itn = array_merge($fields, [
            'pf_payment_id' => 'PF-999',
            'payment_status' => PayFastPaymentStatus::COMPLETE,
            'amount_gross' => $fields['amount'],
            'amount_fee' => '0.00',
            'amount_net' => $fields['amount'],
        ]);
        unset($itn['signature']);
        $itn['signature'] = PayFastSignature::sign($itn, 'pp-secret');

        $this->post('/api/payments/webhook/payfast', $itn)->assertOk()->assertSee('ITN received');

        $quote->refresh();
        $this->assertSame(Quote::PAYMENT_STATUS_PAID, (int) $quote->payment_status);
        $this->assertSame(PaymentRecord::STATUS_SUCCESS, (string) PaymentRecord::query()->findOrFail($recordId)->status);
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function createCompanyAdmin(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant Pay', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'pay-admin-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        return [$tenant, $admin];
    }

    private function createStandardUser(Tenant $tenant): User
    {
        return User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'User',
            'email' => 'pay-user-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => 'user',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    private function createSentQuote(Tenant $tenant, User $admin): Quote
    {
        Sanctum::actingAs($admin);
        $contactId = (int) $this->postJson('/api/contacts', [
            'first_name' => 'Pay',
            'email' => uniqid('pay_', true).'@example.com',
        ])->assertCreated()->json('data.contact.id');

        $pipelineId = (int) $this->postJson('/api/pipelines', ['name' => 'Sales Pay'])->assertCreated()->json('data.pipeline.id');
        $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Lead', 'stage_order' => 1])->assertCreated();

        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'name' => 'Pay Product',
            'sku' => 'PAY-'.uniqid(),
            'unit_price' => 100,
            'tax_rate' => 0,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $quoteId = (int) $this->postJson('/api/quotes', [
            'contact_id' => $contactId,
            'products' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.quote.id');

        $this->patchJson("/api/quotes/{$quoteId}/status", ['status' => 'sent'])->assertOk();

        return Quote::query()->findOrFail($quoteId);
    }
}
