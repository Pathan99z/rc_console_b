<?php

namespace Tests\Feature\DemoLinks;

use App\Models\AuditLog;
use App\Models\DemoLink;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserOrganizationAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ConfiguresEnterpriseStorage;
use Tests\TestCase;

class DemoLinkManagementFullUatTest extends TestCase
{
    use ConfiguresEnterpriseStorage;
    use RefreshDatabase;

    public function test_company_admin_crud_and_encryption(): void
    {
        $ctx = $this->seedHierarchy();
        $product = $this->makeProduct($ctx);
        Sanctum::actingAs($ctx['companyAdmin']);

        $created = $this->postJson('/api/demo-links', [
            'owner_organization_id' => $ctx['partner']->id,
            'title' => 'Convergio Demo',
            'demo_url' => 'https://demo.example.com',
            'demo_username' => 'demo_user',
            'demo_password' => 'SecretPass1!',
            'description' => 'Partner-facing demo',
            'product_ids' => [$product->id],
            'visibility' => [
                [
                    'organization_id' => $ctx['reseller']->id,
                    'include_children' => false,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.demo_link.has_password', true)
            ->assertJsonPath('data.demo_link.demo_password', null)
            ->json('data.demo_link.id');

        $this->assertDatabaseHas('demo_links', [
            'id' => $created,
            'title' => 'Convergio Demo',
        ]);
        $this->assertNotEquals('SecretPass1!', DemoLink::query()->find($created)->demo_password_encrypted);

        $this->getJson("/api/demo-links/{$created}?reveal_credentials=1")
            ->assertOk()
            ->assertJsonPath('data.demo_link.demo_password', 'SecretPass1!');

        $this->patchJson("/api/demo-links/{$created}", [
            'title' => 'Convergio Demo Updated',
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.demo_link.title', 'Convergio Demo Updated');

        $this->deleteJson("/api/demo-links/{$created}")->assertOk();
        $this->assertSoftDeleted('demo_links', ['id' => $created]);

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'demo_links',
            'action' => 'demo_links.create',
            'entity_id' => $created,
        ]);
    }

    public function test_partner_shares_to_child_reseller_and_reseller_can_view(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['partnerAdmin']);

        $linkId = $this->postJson('/api/demo-links', [
            'owner_organization_id' => $ctx['partner']->id,
            'title' => 'RC ISMS Demo',
            'demo_url' => 'https://isms.example.com',
            'visibility' => [
                [
                    'organization_id' => $ctx['reseller']->id,
                    'include_children' => true,
                ],
            ],
        ])->assertCreated()->json('data.demo_link.id');

        Sanctum::actingAs($ctx['resellerAdmin']);
        $this->getJson("/api/demo-links/{$linkId}")->assertOk()
            ->assertJsonPath('data.demo_link.title', 'RC ISMS Demo')
            ->assertJsonPath('data.demo_link.permissions.can_reveal_credentials', true);
    }

    public function test_visibility_target_reseller_can_reveal_when_owner_is_company(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['companyAdmin']);

        $linkId = $this->postJson('/api/demo-links', [
            'owner_organization_id' => $ctx['company']->id,
            'title' => 'Company-owned shared demo',
            'demo_url' => 'https://shared.example.com',
            'demo_password' => 'SharedSecret99!',
            'visibility' => [
                [
                    'organization_id' => $ctx['reseller']->id,
                    'include_children' => false,
                ],
            ],
        ])->assertCreated()->json('data.demo_link.id');

        Sanctum::actingAs($ctx['resellerAdmin']);
        $this->getJson("/api/demo-links/{$linkId}")
            ->assertOk()
            ->assertJsonPath('data.demo_link.permissions.can_reveal_credentials', true);

        $this->getJson("/api/demo-links/{$linkId}?reveal_credentials=1")
            ->assertOk()
            ->assertJsonPath('data.demo_link.demo_password', 'SharedSecret99!');
    }

    public function test_sibling_partner_cannot_access_partner_owned_link(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['partnerAdmin']);

        $linkId = $this->postJson('/api/demo-links', [
            'owner_organization_id' => $ctx['partner']->id,
            'title' => 'Private Partner Demo',
            'demo_url' => 'https://private.example.com',
        ])->assertCreated()->json('data.demo_link.id');

        Sanctum::actingAs($ctx['siblingPartnerAdmin']);
        $this->getJson("/api/demo-links/{$linkId}")->assertNotFound();
    }

    public function test_reseller_cannot_share_outside_own_org(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['resellerAdmin']);

        $this->postJson('/api/demo-links', [
            'owner_organization_id' => $ctx['reseller']->id,
            'title' => 'RC POS Demo',
            'demo_url' => 'https://pos.example.com',
            'visibility' => [
                [
                    'organization_id' => $ctx['partner']->id,
                    'include_children' => false,
                ],
            ],
        ])->assertStatus(422);
    }

    public function test_reseller_admin_own_org_lifecycle(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['resellerAdmin']);

        $linkId = $this->postJson('/api/demo-links', [
            'owner_organization_id' => $ctx['reseller']->id,
            'title' => 'Reseller POS',
            'demo_url' => 'https://reseller-pos.example.com',
        ])->assertCreated()->json('data.demo_link.id');

        $this->getJson('/api/demo-links')->assertOk()
            ->assertJsonFragment(['title' => 'Reseller POS']);

        $this->patchJson("/api/demo-links/{$linkId}", [
            'description' => 'Updated by reseller admin',
        ])->assertOk();
    }

    public function test_consultant_cannot_configure_visibility(): void
    {
        $ctx = $this->seedHierarchy();
        $consultant = $this->makeUser(
            $ctx['tenant']->id,
            Role::CODE_RESELLER_SALES_CONSULTANT,
            'con-'.uniqid('', true).'@example.com',
            $ctx['reseller']->id
        );

        Sanctum::actingAs($consultant);

        $this->postJson('/api/demo-links', [
            'owner_organization_id' => $ctx['reseller']->id,
            'title' => 'Consultant Demo',
            'demo_url' => 'https://consultant.example.com',
            'visibility' => [
                ['organization_id' => $ctx['reseller']->id],
            ],
        ])->assertStatus(422);
    }

    public function test_tenant_isolation_and_idor(): void
    {
        $ctxA = $this->seedHierarchy();
        $ctxB = $this->seedHierarchy('Other Demo Tenant');

        Sanctum::actingAs($ctxA['companyAdmin']);
        $linkId = $this->postJson('/api/demo-links', [
            'owner_organization_id' => $ctxA['company']->id,
            'title' => 'Tenant A Demo',
            'demo_url' => 'https://tenant-a.example.com',
        ])->assertCreated()->json('data.demo_link.id');

        Sanctum::actingAs($ctxB['companyAdmin']);
        $this->getJson("/api/demo-links/{$linkId}")->assertNotFound();
        $this->patchJson("/api/demo-links/{$linkId}", ['title' => 'Hijack'])->assertNotFound();
        $this->deleteJson("/api/demo-links/{$linkId}")->assertNotFound();
    }

    public function test_shareable_organizations_endpoint(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['partnerAdmin']);

        $ids = collect($this->getJson('/api/demo-links/shareable-organizations')->assertOk()
            ->json('data.organizations'))
            ->pluck('id')
            ->all();

        $this->assertContains($ctx['partner']->id, $ids);
        $this->assertContains($ctx['reseller']->id, $ids);
        $this->assertNotContains($ctx['siblingPartner']->id, $ids);
    }

    public function test_screenshot_upload_and_status_check(): void
    {
        $this->fakeEnterpriseStorage('local');
        Http::fake([
            'https://live.example.com' => Http::response('', 200),
        ]);

        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['companyAdmin']);

        $response = $this->post('/api/demo-links', [
            'owner_organization_id' => $ctx['company']->id,
            'title' => 'Live Demo',
            'demo_url' => 'https://live.example.com',
            'screenshot' => UploadedFile::fake()->image('demo.png'),
        ], [
            'Accept' => 'application/json',
        ])->assertCreated()
            ->assertJsonPath('data.demo_link.has_screenshot', true);

        $linkId = $response->json('data.demo_link.id');
        $screenshotUrl = $response->json('data.demo_link.screenshot_url');
        $this->assertIsString($screenshotUrl);
        $this->assertStringContainsString('/api/demo-links/'.$linkId.'/screenshot', $screenshotUrl);

        $parsed = parse_url($screenshotUrl);
        $this->assertNotFalse($parsed);
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';
        $this->get(($parsed['path'] ?? '').$query)->assertOk();

        $this->postJson("/api/demo-links/{$linkId}/check-status")
            ->assertOk()
            ->assertJsonPath('data.status.last_status', DemoLink::STATUS_UP);

        $this->assertNotNull(DemoLink::query()->find($linkId)->last_checked_at);
    }

    public function test_invalid_product_rejected(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['companyAdmin']);

        $this->postJson('/api/demo-links', [
            'owner_organization_id' => $ctx['company']->id,
            'title' => 'Bad Product',
            'demo_url' => 'https://bad.example.com',
            'product_ids' => [999999],
        ])->assertStatus(422);
    }

    public function test_regression_smoke_existing_modules(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['companyAdmin']);

        $this->getJson('/api/contacts')->assertOk();
        $this->getJson('/api/deals')->assertOk();
        $this->getJson('/api/tasks')->assertOk();
        $this->getJson('/api/prm/commission-accruals')->assertOk();
        $this->getJson('/api/prm/payouts')->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function seedHierarchy(?string $tenantName = 'Demo Link Tenant'): array
    {
        $tenant = Tenant::query()->create(['name' => $tenantName, 'status' => Tenant::STATUS_ACTIVE]);
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
            'legal_name' => 'Co',
            'display_name' => 'Co',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $partner = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner',
            'display_name' => 'Partner',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $siblingPartner = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Sibling Partner',
            'display_name' => 'Sibling Partner',
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
            'legal_name' => 'Reseller',
            'display_name' => 'Reseller',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $siblingReseller = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $siblingPartner->id,
            'type' => Organization::TYPE_RESELLER,
            'channel_mode' => Organization::CHANNEL_MODE_PARTNER_MANAGED,
            'legal_name' => 'Sibling Reseller',
            'display_name' => 'Sibling Reseller',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        $partnerAdmin = $this->makeUser($tenant->id, Role::CODE_PARTNER_ADMIN, 'pa-'.uniqid('', true).'@example.com', $partner->id);
        $siblingPartnerAdmin = $this->makeUser($tenant->id, Role::CODE_PARTNER_ADMIN, 'spa-'.uniqid('', true).'@example.com', $siblingPartner->id);
        $resellerAdmin = $this->makeUser($tenant->id, Role::CODE_RESELLER_ADMIN, 'ra-'.uniqid('', true).'@example.com', $reseller->id);

        return compact(
            'tenant',
            'companyAdmin',
            'company',
            'partner',
            'siblingPartner',
            'reseller',
            'siblingReseller',
            'partnerAdmin',
            'siblingPartnerAdmin',
            'resellerAdmin',
        );
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function makeProduct(array $ctx): Product
    {
        return Product::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'created_by_user_id' => $ctx['companyAdmin']->id,
            'updated_by_user_id' => $ctx['companyAdmin']->id,
            'name' => 'RC Product',
            'sku' => 'SKU-'.uniqid(),
            'unit_price' => 100,
            'tax_rate' => 15,
            'status' => Product::STATUS_ACTIVE,
        ]);
    }

    private function makeUser(int $tenantId, string $role, string $email, ?int $orgId): User
    {
        $user = User::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'U '.$email,
            'email' => $email,
            'password' => 'secret123',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        if ($orgId !== null) {
            UserOrganizationAssignment::query()->create([
                'user_id' => $user->id,
                'organization_id' => $orgId,
            ]);
        }

        return $user->fresh(['organizationAssignment.organization']);
    }
}
