<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Product;
use App\Models\Quote;
use App\Models\Role;
use App\Models\User;
use App\Services\Quote\QuoteService;
use App\Support\Notifications\InAppNotificationTemplateKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Concerns\BuildsTenantUsersForNotifications;
use Tests\TestCase;

/**
 * Template keys mirror production naming: quotes.sent, quotes.accepted,
 * quotes.rejected, payments.initiated / payments.success / payments.failed (+ quote-specific parallels).
 */
class QuoteNotificationTest extends TestCase
{
    use BuildsTenantUsersForNotifications;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stubPayFastCredentials();
        Mail::fake();
    }

    private function stubPayFastCredentials(): void
    {
        config([
            'payfast.fallback_merchant_id' => '10000100',
            'payfast.fallback_merchant_key' => 'test-merchant-secret',
            'payfast.fallback_passphrase' => 'passphrase-test',
            'payfast.fallback_mode' => 'sandbox',
        ]);
    }

    /**
     * @return array{\App\Models\Tenant, User, int}
     */
    private function quotingSetup(): array
    {
        $tenant = $this->makeActiveTenant();
        $admin = $this->makeCompanyAdmin($tenant);
        Sanctum::actingAs($admin);

        $contactId = (int) $this->postJson('/api/contacts', [
            'first_name' => 'Quote',
            'email' => 'quote-n-'.uniqid('', true).'@example.com',
        ])->assertCreated()->json('data.contact.id');

        $pipelineId = (int) $this->postJson('/api/pipelines', ['name' => 'QP'])->assertCreated()->json('data.pipeline.id');
        $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Lead', 'stage_order' => 1])->assertCreated();

        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'name' => 'Seat',
            'sku' => 'SKU-'.uniqid(),
            'unit_price' => 500,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $quoteId = (int) $this->postJson('/api/quotes', [
            'contact_id' => $contactId,
            'products' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.quote.id');

        return [$tenant, $admin, $quoteId];
    }

    public function test_quote_sent_dispatches_quotes_sent(): void
    {
        [$tenant, $admin, $quoteId] = $this->quotingSetup();

        $this->postJson("/api/quotes/{$quoteId}/send", [
            'layout_code' => 'modern',
            'attach_pdf' => false,
            'message' => 'FYI',
        ])->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $admin->tenant_id,
            'recipient_user_id' => $admin->id,
            'notification_type' => InAppNotificationTemplateKeys::QUOTES_SENT,
        ]);
    }

    public function test_quote_accepted_dispatches_quotes_accepted_and_deals_won_side_effects_exist(): void
    {
        $tenant = $this->makeActiveTenant();
        $admin = $this->makeCompanyAdmin($tenant);
        Sanctum::actingAs($admin);

        $contactId = (int) $this->postJson('/api/contacts', [
            'first_name' => 'Won',
            'email' => 'won-n-'.uniqid('', true).'@example.com',
        ])->assertCreated()->json('data.contact.id');

        $pipelineId = (int) $this->postJson('/api/pipelines', ['name' => 'S'])->assertCreated()->json('data.pipeline.id');
        $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Lead', 'stage_order' => 1])->assertCreated();
        $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Closed Won', 'stage_order' => 9])->assertCreated();

        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'name' => 'Lic',
            'sku' => 'WIN-'.uniqid(),
            'unit_price' => 100,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $quoteId = (int) $this->postJson('/api/quotes', [
            'contact_id' => $contactId,
            'products' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.quote.id');

        $quote = Quote::query()->findOrFail($quoteId);
        $token = $quote->public_uuid;

        $this->postJson("/api/quotes/public/{$token}/accept")->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $admin->id,
            'notification_type' => InAppNotificationTemplateKeys::QUOTES_ACCEPTED,
        ]);

        $this->assertGreaterThanOrEqual(
            1,
            \App\Models\InAppNotification::query()
                ->where('tenant_id', $tenant->id)
                ->where('notification_type', InAppNotificationTemplateKeys::DEALS_WON)
                ->count()
        );
    }

    public function test_quote_rejected_dispatches_quotes_rejected(): void
    {
        [$tenant, $admin, $quoteId] = $this->quotingSetup();

        $quote = Quote::query()->findOrFail($quoteId);
        $this->postJson("/api/quotes/public/{$quote->public_uuid}/reject")->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $admin->tenant_id,
            'recipient_user_id' => $admin->id,
            'notification_type' => InAppNotificationTemplateKeys::QUOTES_REJECTED,
        ]);
    }

    public function test_payment_link_initiated_via_generate_records_payments_initiated(): void
    {
        [$tenant, $admin, $quoteId] = $this->quotingSetup();

        Quote::query()->whereKey($quoteId)->update([
            'status' => Quote::STATUS_SENT,
        ]);

        $this->postJson("/api/quotes/{$quoteId}/payment-link")->assertOk();

        $this->assertGreaterThanOrEqual(
            1,
            \App\Models\InAppNotification::query()
                ->where('tenant_id', $admin->tenant_id)
                ->where('notification_type', InAppNotificationTemplateKeys::PAYMENTS_INITIATED)
                ->where('recipient_user_id', $admin->id)
                ->count()
        );
    }

    public function test_payment_success_via_quote_service_writes_quotes_payment_success_and_payments_success(): void
    {
        [$tenant, $admin, $quoteId] = $this->quotingSetup();

        Quote::query()->whereKey($quoteId)->update([
            'status' => Quote::STATUS_SENT,
        ]);

        $quote = Quote::query()->findOrFail($quoteId);
        $finance = User::query()->create([
            'tenant_id' => $quote->tenant_id,
            'name' => 'Finance',
            'email' => 'finance-n-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_FINANCE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        app(QuoteService::class)->applySuccessfulPayment($quoteId, null, 999901);

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $quote->tenant_id,
            'recipient_user_id' => $admin->id,
            'notification_type' => InAppNotificationTemplateKeys::QUOTES_PAYMENT_SUCCESS,
        ]);

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $quote->tenant_id,
            'recipient_user_id' => $finance->id,
            'notification_type' => InAppNotificationTemplateKeys::PAYMENTS_SUCCESS,
        ]);
    }

    public function test_quote_payment_failed_event_writes_parallel_failed_rows(): void
    {
        $tenant = $this->makeActiveTenant();
        $admin = $this->makeCompanyAdmin($tenant);
        Sanctum::actingAs($admin);

        $cid = (int) $this->postJson('/api/contacts', [
            'first_name' => 'PF',
            'email' => 'pf-'.uniqid('', true).'@example.com',
        ])->assertCreated()->json('data.contact.id');

        $pipelineId = (int) $this->postJson('/api/pipelines', ['name' => 'P'])->assertCreated()->json('data.pipeline.id');
        $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Lead', 'stage_order' => 1])->assertCreated();

        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'name' => 'X',
            'sku' => 'PF-'.uniqid(),
            'unit_price' => 10,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $quoteId = (int) $this->postJson('/api/quotes', [
            'contact_id' => $cid,
            'products' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.quote.id');

        Quote::query()->whereKey($quoteId)->update(['status' => Quote::STATUS_SENT]);

        event(new \App\Events\Notifications\QuotePaymentFailed($quoteId, 881));

        $this->assertGreaterThanOrEqual(
            1,
            \App\Models\InAppNotification::query()
                ->where('tenant_id', $tenant->id)
                ->where('notification_type', InAppNotificationTemplateKeys::QUOTES_PAYMENT_FAILED)
                ->where('recipient_user_id', $admin->id)
                ->count(),
        );

        $this->assertGreaterThanOrEqual(
            1,
            \App\Models\InAppNotification::query()
                ->where('tenant_id', $tenant->id)
                ->where('notification_type', InAppNotificationTemplateKeys::PAYMENTS_FAILED)
                ->where('recipient_user_id', $admin->id)
                ->count(),
        );
    }
}
