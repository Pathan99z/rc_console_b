<?php

namespace Tests\Feature\Quote;

use App\Mail\QuoteSharedMail;
use App\Mail\QuotePaymentLinkMail;
use App\Models\Deal;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuoteManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_creation_auto_creates_deal_and_syncs_value(): void
    {
        [$tenant, $admin] = $this->createContext();
        Sanctum::actingAs($admin);
        $contactId = (int) $this->postJson('/api/contacts', ['first_name' => 'Quote', 'email' => 'quote-flow@example.com'])
            ->assertCreated()->json('data.contact.id');
        $pipelineId = (int) $this->postJson('/api/pipelines', ['name' => 'Default'])->assertCreated()->json('data.pipeline.id');
        $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Lead', 'stage_order' => 1])->assertCreated();
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'name' => 'CRM Suite',
            'sku' => 'CRM-100',
            'unit_price' => 1000,
            'tax_rate' => 10,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $response = $this->postJson('/api/quotes', [
            'contact_id' => $contactId,
            'discount_total' => 100,
            'products' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ])->assertCreated();

        $quoteId = (int) $response->json('data.quote.id');
        $dealId = (int) $response->json('data.quote.deal.id');
        $deal = Deal::query()->findOrFail($dealId);
        $this->assertSame($quoteId, (int) $deal->last_quote_id);
        $this->assertSame('2100.00', (string) $deal->estimated_value);
    }

    public function test_accepting_quote_marks_deal_won(): void
    {
        [$tenant, $admin] = $this->createContext();
        Sanctum::actingAs($admin);
        $contactId = (int) $this->postJson('/api/contacts', ['first_name' => 'Won', 'email' => 'quote-won@example.com'])
            ->assertCreated()->json('data.contact.id');
        $pipelineId = (int) $this->postJson('/api/pipelines', ['name' => 'Sales'])->assertCreated()->json('data.pipeline.id');
        $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Lead', 'stage_order' => 1])->assertCreated();
        $finalStageId = (int) $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Closed Won', 'stage_order' => 9])->assertCreated()->json('data.stage.id');
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'name' => 'Package',
            'sku' => 'PKG-100',
            'unit_price' => 500,
            'status' => Product::STATUS_ACTIVE,
        ]);
        $quoteId = (int) $this->postJson('/api/quotes', [
            'contact_id' => $contactId,
            'products' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.quote.id');

        $this->patchJson("/api/quotes/{$quoteId}/status", ['status' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('data.quote.status', 'accepted');

        $dealId = (int) $this->getJson("/api/quotes/{$quoteId}")->json('data.quote.deal.id');
        $deal = Deal::query()->findOrFail($dealId);
        $this->assertSame(Deal::STATUS_WON, (int) $deal->status);
        $this->assertSame($finalStageId, (int) $deal->pipeline_stage_id);
    }

    public function test_quote_attachment_upload_works(): void
    {
        [$tenant, $admin] = $this->createContext();
        Sanctum::actingAs($admin);
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $contactId = (int) $this->postJson('/api/contacts', ['first_name' => 'Attach', 'email' => 'quote-attach@example.com'])
            ->assertCreated()->json('data.contact.id');
        $pipelineId = (int) $this->postJson('/api/pipelines', ['name' => 'Sales'])->assertCreated()->json('data.pipeline.id');
        $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Lead', 'stage_order' => 1])->assertCreated();
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'name' => 'Package',
            'unit_price' => 500,
            'status' => Product::STATUS_ACTIVE,
        ]);
        $quoteId = (int) $this->postJson('/api/quotes', [
            'contact_id' => $contactId,
            'products' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.quote.id');

        $this->post("/api/quotes/{$quoteId}/attachments", [
            'name' => 'Proposal PDF',
            'file' => UploadedFile::fake()->create('proposal.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.attachment.name', 'Proposal PDF');
    }

    public function test_public_quote_endpoints_allow_view_and_accept(): void
    {
        [$tenant, $admin] = $this->createContext();
        Sanctum::actingAs($admin);
        $contactId = (int) $this->postJson('/api/contacts', ['first_name' => 'Public', 'email' => 'quote-public@example.com'])
            ->assertCreated()->json('data.contact.id');
        $pipelineId = (int) $this->postJson('/api/pipelines', ['name' => 'Sales'])->assertCreated()->json('data.pipeline.id');
        $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Lead', 'stage_order' => 1])->assertCreated();
        $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Won', 'stage_order' => 9])->assertCreated();
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'name' => 'Public Product',
            'unit_price' => 100,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $quoteId = (int) $this->postJson('/api/quotes', [
            'contact_id' => $contactId,
            'products' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.quote.id');
        $token = (string) $this->getJson("/api/quotes/{$quoteId}")->json('data.quote.public_uuid');

        $this->getJson("/api/quotes/public/{$token}")
            ->assertOk()
            ->assertJsonPath('data.quote.id', $quoteId);

        $this->postJson("/api/quotes/public/{$token}/accept")
            ->assertOk()
            ->assertJsonPath('data.quote.status', 'accepted');
    }

    public function test_quote_send_endpoint_sends_email_and_marks_sent(): void
    {
        [$tenant, $admin] = $this->createContext();
        Sanctum::actingAs($admin);
        Mail::fake();

        $contactId = (int) $this->postJson('/api/contacts', ['first_name' => 'Email', 'email' => 'quote-mail@example.com'])
            ->assertCreated()->json('data.contact.id');
        $pipelineId = (int) $this->postJson('/api/pipelines', ['name' => 'Sales'])->assertCreated()->json('data.pipeline.id');
        $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Lead', 'stage_order' => 1])->assertCreated();
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'name' => 'Mail Product',
            'unit_price' => 250,
            'status' => Product::STATUS_ACTIVE,
        ]);
        $quoteId = (int) $this->postJson('/api/quotes', [
            'contact_id' => $contactId,
            'products' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.quote.id');

        $this->postJson("/api/quotes/{$quoteId}/send", [
            'layout_code' => 'modern',
            'attach_pdf' => true,
            'message' => 'Please review and confirm.',
        ])->assertOk()->assertJsonPath('data.quote.status', 'sent');

        Mail::assertSent(QuoteSharedMail::class, function (QuoteSharedMail $mail): bool {
            return $mail->hasTo('quote-mail@example.com') && count($mail->attachments()) === 1;
        });
    }

    public function test_quote_send_payment_link_endpoint_sends_payment_email(): void
    {
        [$tenant, $admin] = $this->createContext();
        Sanctum::actingAs($admin);
        Mail::fake();

        $contactId = (int) $this->postJson('/api/contacts', ['first_name' => 'Pay', 'email' => 'quote-pay@example.com'])
            ->assertCreated()->json('data.contact.id');
        $pipelineId = (int) $this->postJson('/api/pipelines', ['name' => 'Sales'])->assertCreated()->json('data.pipeline.id');
        $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Lead', 'stage_order' => 1])->assertCreated();
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'name' => 'Pay Product',
            'unit_price' => 250,
            'status' => Product::STATUS_ACTIVE,
        ]);
        $quoteId = (int) $this->postJson('/api/quotes', [
            'contact_id' => $contactId,
            'products' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.quote.id');

        $this->postJson("/api/quotes/{$quoteId}/send-payment-link", [
            'message' => 'Please use this secure payment link.',
        ])->assertOk()->assertJsonPath('data.quote.status', 'sent');

        Mail::assertSent(QuotePaymentLinkMail::class, function (QuotePaymentLinkMail $mail): bool {
            return $mail->hasTo('quote-pay@example.com') && str_contains($mail->paymentUrl, '/payments/link/');
        });
    }

    public function test_quote_payment_link_create_then_send_flow_works(): void
    {
        [$tenant, $admin] = $this->createContext();
        Sanctum::actingAs($admin);
        Mail::fake();

        $contactId = (int) $this->postJson('/api/contacts', ['first_name' => 'Token', 'email' => 'quote-token@example.com'])
            ->assertCreated()->json('data.contact.id');
        $pipelineId = (int) $this->postJson('/api/pipelines', ['name' => 'Sales'])->assertCreated()->json('data.pipeline.id');
        $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Lead', 'stage_order' => 1])->assertCreated();
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'name' => 'Pay Token Product',
            'unit_price' => 250,
            'status' => Product::STATUS_ACTIVE,
        ]);
        $quoteId = (int) $this->postJson('/api/quotes', [
            'contact_id' => $contactId,
            'products' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.quote.id');

        $createResponse = $this->postJson("/api/quotes/{$quoteId}/payment-links", [])
            ->assertCreated()
            ->assertJsonPath('data.payment_link.status', 'created');
        $linkId = (int) $createResponse->json('data.payment_link.id');
        $this->assertNotEmpty($createResponse->json('data.payment_link.url'));

        $this->postJson("/api/quotes/{$quoteId}/payment-links/{$linkId}/send", [
            'message' => 'Secure payment link generated for you.',
        ])->assertOk()
            ->assertJsonPath('data.payment_link.status', 'sent');

        Mail::assertSent(QuotePaymentLinkMail::class, function (QuotePaymentLinkMail $mail): bool {
            return $mail->hasTo('quote-token@example.com') && str_contains($mail->paymentUrl, '/payments/link/');
        });
    }

    public function test_quote_layouts_endpoint_returns_default_templates(): void
    {
        [, $admin] = $this->createContext();
        Sanctum::actingAs($admin);

        $this->getJson('/api/quote-layouts')
            ->assertOk()
            ->assertJsonPath('data.items.0.code', 'classic')
            ->assertJsonPath('data.items.1.code', 'modern')
            ->assertJsonPath('data.items.2.code', 'minimal')
            ->assertJsonPath('data.items.3.code', 'detailed');
    }

    public function test_quote_preview_prices_endpoint_returns_live_totals(): void
    {
        [$tenant, $admin] = $this->createContext();
        Sanctum::actingAs($admin);
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'name' => 'Preview Product',
            'unit_price' => 1000,
            'tax_rate' => 10,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $this->postJson('/api/quotes/preview-prices', [
            'products' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'discount' => 50,
                ],
            ],
            'discount_total' => 100,
            'target_currency' => 'zar',
        ])->assertOk()
            ->assertJsonPath('data.items.0.line_subtotal', 2000)
            ->assertJsonPath('data.items.0.line_tax_total', 200)
            ->assertJsonPath('data.items.0.line_discount_total', 50)
            ->assertJsonPath('data.total', 2050)
            ->assertJsonPath('data.currency_code', 'ZAR');
    }

    private function createContext(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'quote-admin-'.uniqid()."@example.com",
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        return [$tenant, $admin];
    }
}
