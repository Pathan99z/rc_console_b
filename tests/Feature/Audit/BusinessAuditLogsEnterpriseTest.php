<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\DealHistory;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\BusinessAuditEventKeys;
use App\Support\Payment\PayFastPaymentStatus;
use App\Support\Payment\PayFastSignature;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class BusinessAuditLogsEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: User} */
    private function tenantWithCompanyAdmin(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant Audit', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Company Admin',
            'email' => 'audit-ca-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        return [$tenant, $admin];
    }

    public function test_partner_admin_cannot_view_audit_console(): void
    {
        [$tenant] = $this->tenantWithCompanyAdmin();
        $partner = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Partner',
            'email' => 'audit-pa-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => 'partner_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($partner);

        $this->getJson('/api/audit-logs')->assertForbidden();
        $this->getJson('/api/audit-logs/export')->assertForbidden();
    }

    public function test_company_admin_sees_only_own_tenant_audit_rows(): void
    {
        [$tenantA, $adminA] = $this->tenantWithCompanyAdmin();
        [$tenantB] = $this->tenantWithCompanyAdmin();

        DB::table('audit_logs')->insert([
            'tenant_id' => $tenantB->id,
            'user_id' => null,
            'module' => 'system',
            'action' => 'probe',
            'entity_type' => 'test',
            'entity_id' => 0,
            'before' => null,
            'after' => null,
            'ip_address' => null,
            'user_agent' => null,
            'event_key' => 'custom.probe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('audit_logs')->insert([
            'tenant_id' => $tenantA->id,
            'user_id' => $adminA->id,
            'module' => 'system',
            'action' => 'probe_a',
            'entity_type' => 'test',
            'entity_id' => 1,
            'before' => null,
            'after' => null,
            'ip_address' => null,
            'user_agent' => null,
            'event_key' => BusinessAuditEventKeys::AUTH_LOGIN_SUCCESS,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($adminA);

        $eventKeys = collect($this->getJson('/api/audit-logs')->assertOk()->json('data.items'))
            ->pluck('event_key')
            ->all();

        $this->assertContains(BusinessAuditEventKeys::AUTH_LOGIN_SUCCESS, $eventKeys);
        $this->assertNotContains('custom.probe', $eventKeys);
    }

    public function test_login_success_records_correlation_header_on_audit_row(): void
    {
        [$tenant, $admin] = $this->tenantWithCompanyAdmin();

        $cid = 'test-correlation-'.uniqid();
        $this->withHeader(config('audit.correlation_header'), $cid)->postJson('/api/login', [
            'email' => $admin->email,
            'password' => 'secret123',
            'device_name' => 'pytest',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event_key' => BusinessAuditEventKeys::AUTH_LOGIN_SUCCESS,
            'correlation_id' => $cid,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_contact_creation_emits_catalog_event_key(): void
    {
        [$tenant, $admin] = $this->tenantWithCompanyAdmin();
        Sanctum::actingAs($admin);

        $cid = uniqid('c_', true).'@example.com';
        $this->postJson('/api/contacts', [
            'first_name' => 'Audited',
            'email' => $cid,
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'module' => 'contact',
            'event_key' => BusinessAuditEventKeys::CONTACTS_CREATED,
            'user_id' => $admin->id,
        ]);

        /** @phpstan-ignore-next-line */
        $logs = AuditLog::query()->where('event_key', BusinessAuditEventKeys::CONTACTS_CREATED)->latest('id')->first();
        $this->assertNotNull($logs);
        $payload = json_encode($logs->after ?? [], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsStringIgnoringCase('password', $payload);
    }

    public function test_audit_index_filter_by_event_key(): void
    {
        [, $admin] = $this->tenantWithCompanyAdmin();
        DB::table('audit_logs')->insert([
            'tenant_id' => $admin->tenant_id,
            'user_id' => $admin->id,
            'module' => 'payments',
            'action' => 'initiated',
            'entity_type' => 'payment_record',
            'entity_id' => 99,
            'before' => null,
            'after' => null,
            'ip_address' => null,
            'user_agent' => null,
            'event_key' => BusinessAuditEventKeys::PAYMENTS_INITIATED,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($admin);

        $items = $this->getJson('/api/audit-logs?'.http_build_query([
            'event_key' => BusinessAuditEventKeys::PAYMENTS_INITIATED,
        ]))->assertOk()->json('data.items');

        $this->assertNotEmpty($items);
        foreach ($items as $row) {
            $this->assertSame(BusinessAuditEventKeys::PAYMENTS_INITIATED, $row['event_key']);
        }
    }

    public function test_payfast_success_webhook_writes_payment_webhook_audit(): void
    {
        [$tenant, $admin] = $this->tenantWithCompanyAdmin();
        Sanctum::actingAs($admin);
        $this->postJson('/api/settings/payment', [
            'payfast_mode' => 'sandbox',
            'merchant_id' => '10000100',
            'merchant_key' => 'secretmerchant',
            'passphrase' => 'pp-secret',
        ])->assertCreated();

        Sanctum::actingAs($admin);
        $contactId = (int) $this->postJson('/api/contacts', [
            'first_name' => 'Pay',
            'email' => uniqid('audit_pay_', true).'@example.com',
        ])->assertCreated()->json('data.contact.id');

        $pipelineId = (int) $this->postJson('/api/pipelines', ['name' => 'Audit Pay'])->assertCreated()->json('data.pipeline.id');
        $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Lead', 'stage_order' => 1])->assertCreated();

        $quoteId = $this->createQuoteForTenant($tenant, $admin, $contactId);

        Sanctum::actingAs($admin);
        $link = $this->postJson("/api/quotes/{$quoteId}/payment-link")->assertOk()->json('data');
        $recordId = (int) $link['payment_record_id'];
        $fields = $link['fields'];

        $itn = array_merge($fields, [
            'pf_payment_id' => 'PF-AUD',
            'payment_status' => PayFastPaymentStatus::COMPLETE,
            'amount_gross' => $fields['amount'],
            'amount_fee' => '0.00',
            'amount_net' => $fields['amount'],
        ]);
        unset($itn['signature']);
        $itn['signature'] = PayFastSignature::sign($itn, 'pp-secret');

        $this->post('/api/payments/webhook/payfast', $itn)->assertOk();

        Quote::query()->findOrFail($quoteId)->refresh();

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'module' => 'payments',
            'action' => 'webhook_success',
            'event_key' => BusinessAuditEventKeys::PAYMENTS_WEBHOOK_SUCCESS,
            'entity_id' => $recordId,
        ]);
    }

    public function test_unified_list_contains_deal_history_stream(): void
    {
        [$tenant, $admin] = $this->tenantWithCompanyAdmin();
        Sanctum::actingAs($admin);

        $pipelineId = (int) $this->postJson('/api/pipelines', ['name' => 'Audit Deals'])->assertCreated()->json('data.pipeline.id');
        $stageId = (int) $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'S1', 'stage_order' => 1])->assertCreated()->json('data.stage.id');

        $contactId = (int) $this->postJson('/api/contacts', [
            'first_name' => 'Deal',
            'email' => uniqid('audit_deal_', true).'@example.com',
        ])->assertCreated()->json('data.contact.id');

        $dealId = (int) $this->postJson('/api/deals', [
            'name' => 'Tracked',
            'contact_id' => $contactId,
            'owner_user_id' => $admin->id,
            'pipeline_id' => $pipelineId,
            'pipeline_stage_id' => $stageId,
        ])->assertCreated()->json('data.deal.id');

        DealHistory::query()->create([
            'tenant_id' => $tenant->id,
            'deal_id' => $dealId,
            'user_id' => $admin->id,
            'type' => 'owner_changed',
            'from_value' => '1',
            'to_value' => '2',
            'notes' => 'unit',
            'meta' => [],
        ]);

        Sanctum::actingAs($admin);
        $items = $this->getJson('/api/audit-logs?per_page=50')->assertOk()->json('data.items');

        $dh = collect($items)->firstWhere('stream', 'deal_history');
        $this->assertNotNull($dh);
        $this->assertStringStartsWith('dh-', $dh['public_id']);
        $this->assertSame(BusinessAuditEventKeys::DEALS_OWNER_CHANGED, $dh['event_key']);

        $publicId = $dh['public_id'];
        $this->getJson("/api/audit-logs/{$publicId}")
            ->assertOk()
            ->assertJsonPath('data.entry.public_id', $publicId);
    }

    public function test_csv_export_streams_rows(): void
    {
        [$tenant, $admin] = $this->tenantWithCompanyAdmin();
        Sanctum::actingAs($admin);

        DB::table('audit_logs')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'module' => 'payments',
            'action' => 'initiated',
            'entity_type' => 'payment_record',
            'entity_id' => 7,
            'before' => null,
            'after' => null,
            'ip_address' => null,
            'user_agent' => null,
            'event_key' => BusinessAuditEventKeys::PAYMENTS_INITIATED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/api/audit-logs/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('public_id', (string) $response->streamedContent());
        $this->assertStringContainsString(BusinessAuditEventKeys::PAYMENTS_INITIATED, (string) $response->streamedContent());
    }

    public function test_audit_archive_marks_old_rows_and_hides_from_default_query(): void
    {
        Carbon::setTestNow(Carbon::parse('2028-06-01 12:00:00'));

        try {
            [$tenant, $admin] = $this->tenantWithCompanyAdmin();
            DB::table('audit_logs')->insert([
                'tenant_id' => $tenant->id,
                'user_id' => $admin->id,
                'module' => 'system',
                'action' => 'old',
                'entity_type' => 'x',
                'entity_id' => 1,
                'before' => null,
                'after' => null,
                'ip_address' => null,
                'user_agent' => null,
                'event_key' => 'legacy.archived.probe',
                'created_at' => '2027-01-01 08:00:00',
                'updated_at' => '2027-01-01 08:00:00',
            ]);

            $this->artisan('audit:archive')->assertSuccessful();

            Sanctum::actingAs($admin);
            $filtered = collect($this->getJson('/api/audit-logs?per_page=100')->json('data.items'))
                ->pluck('event_key')
                ->all();
            $this->assertNotContains('legacy.archived.probe', $filtered);

            $withArchived = collect($this->getJson('/api/audit-logs?include_archived=1&per_page=100')->json('data.items'))
                ->pluck('event_key')
                ->all();
            $this->assertContains('legacy.archived.probe', $withArchived);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_audit_purge_force_deletes_rows_past_threshold(): void
    {
        $logId = DB::table('audit_logs')->insertGetId([
            'tenant_id' => null,
            'user_id' => null,
            'module' => 'system',
            'action' => 'purge_me',
            'entity_type' => 'x',
            'entity_id' => 9,
            'before' => null,
            'after' => null,
            'ip_address' => null,
            'user_agent' => null,
            'event_key' => 'purge.test',
            'created_at' => now()->subDays(4000),
            'updated_at' => now()->subDays(4000),
        ]);

        $this->artisan('audit:purge', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $logId]);
    }

    public function test_audit_logs_are_immutable_via_eloquent_save(): void
    {
        $log = AuditLog::query()->create([
            'tenant_id' => null,
            'user_id' => null,
            'module' => 'system',
            'action' => 'immutable_test',
            'entity_type' => 'x',
            'entity_id' => 5,
            'before' => null,
            'after' => null,
        ]);

        $this->expectException(\LogicException::class);
        $log->action = 'changed';
        $log->save();
    }

    /** Bootstrap a sent quote tied to tenant with products (minimal duplication from payment tests). */
    private function createQuoteForTenant(Tenant $tenant, User $admin, int $contactId): int
    {
        Sanctum::actingAs($admin);

        $product = \App\Models\Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'name' => 'Audit SKU',
            'sku' => 'AUD-'.uniqid(),
            'unit_price' => 50,
            'tax_rate' => 0,
            'status' => \App\Models\Product::STATUS_ACTIVE,
        ]);

        $quoteId = (int) $this->postJson('/api/quotes', [
            'contact_id' => $contactId,
            'products' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.quote.id');

        $this->patchJson("/api/quotes/{$quoteId}/status", ['status' => 'sent'])->assertOk();

        return $quoteId;
    }
}
