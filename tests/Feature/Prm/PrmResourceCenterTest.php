<?php

namespace Tests\Feature\Prm;

use App\Models\Collateral;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserOrganizationAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ConfiguresEnterpriseStorage;
use Tests\TestCase;

class PrmResourceCenterTest extends TestCase
{
    use ConfiguresEnterpriseStorage;
    use RefreshDatabase;

    public function test_company_admin_can_manage_prm_resources_and_analytics(): void
    {
        $this->fakeEnterpriseStorage('local');
        [$tenant, $companyAdmin, $partnerOrg, $product] = $this->tenantAdminPartnerProduct();

        Sanctum::actingAs($companyAdmin);

        $this->getJson('/api/prm/resources')
            ->assertOk()
            ->assertJsonPath('data.items', []);

        $file = UploadedFile::fake()->create('guide.pdf', 80, 'application/pdf');

        $create = $this->post('/api/prm/resources', [
            'title' => 'Channel Guide',
            'description' => 'Overview',
            'resource_category' => 'training',
            'product_id' => $product->id,
            'file' => $file,
            'partner_visible' => true,
            'reseller_visible' => false,
            'status' => 'active',
        ], [
            'Accept' => 'application/json',
        ]);

        $create->assertCreated();
        $id = (int) $create->json('data.resource.id');
        $this->assertSame('Channel Guide', $create->json('data.resource.title'));
        $this->assertSame(0, $create->json('data.resource.download_count'));
        $create->assertJsonStructure([
            'data' => [
                'resource' => [
                    'signed_url',
                    'file' => ['file_type', 'file_size'],
                ],
            ],
        ]);
        $this->assertNotEmpty($create->json('data.resource.signed_url'));

        $this->getJson('/api/prm/resources/'.$id)
            ->assertOk()
            ->assertJsonPath('data.resource.id', $id)
            ->assertJsonStructure([
                'data' => [
                    'resource' => [
                        'signed_url',
                        'file' => ['file_type', 'file_size'],
                    ],
                ],
            ]);

        $this->getJson('/api/prm/resources')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $id)
            ->assertJsonStructure([
                'data' => [
                    'items' => [
                        [
                            'signed_url',
                            'file' => ['file_type', 'file_size'],
                        ],
                    ],
                ],
            ]);

        $this->patchJson('/api/prm/resources/'.$id.'/status', ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.resource.status', 'inactive');

        $this->patchJson('/api/prm/resources/'.$id.'/status', ['status' => 'active'])
            ->assertOk();

        $updateFile = UploadedFile::fake()->create('guide2.pdf', 80, 'application/pdf');
        $this->put('/api/prm/resources/'.$id, [
            'title' => 'Channel Guide v2',
            'resource_category' => 'training',
            'file' => $updateFile,
            'partner_visible' => true,
            'reseller_visible' => true,
            'status' => 'active',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.resource.title', 'Channel Guide v2');

        $this->getJson('/api/prm/resources/analytics')
            ->assertOk()
            ->assertJsonPath('data.total_resources', 1)
            ->assertJsonPath('data.active_resources', 1);

        $this->deleteJson('/api/prm/resources/'.$id)
            ->assertOk();

        $this->assertSoftDeleted('collaterals', ['id' => $id]);
    }

    public function test_partner_lists_filters_and_download_with_audit(): void
    {
        $this->fakeEnterpriseStorage('local');
        [$tenant, $companyAdmin, $partnerOrg, $product] = $this->tenantAdminPartnerProduct();

        $visible = Collateral::query()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'name' => 'Alpha Brochure',
            'description' => 'Alpha desc',
            'type' => 'brochure',
            'file_key' => 'tenant/'.$tenant->id.'/collaterals/test-alpha.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 100,
            'partner_visible' => true,
            'reseller_visible' => false,
            'resource_category' => 'brochure',
            'status' => Collateral::STATUS_ACTIVE,
            'metadata' => null,
        ]);
        Storage::disk('local')->put($visible->file_key, 'pdf');

        $hiddenInactive = Collateral::query()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'name' => 'Hidden',
            'description' => null,
            'type' => 'x',
            'file_key' => 'tenant/'.$tenant->id.'/collaterals/test-hidden.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 50,
            'partner_visible' => true,
            'reseller_visible' => false,
            'resource_category' => 'brochure',
            'status' => Collateral::STATUS_INACTIVE,
            'metadata' => null,
        ]);
        Storage::disk('local')->put($hiddenInactive->file_key, 'pdf');

        $partnerUser = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Partner User',
            'email' => 'partner-res-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_PARTNER_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        UserOrganizationAssignment::query()->create([
            'user_id' => $partnerUser->id,
            'organization_id' => $partnerOrg->id,
        ]);

        Sanctum::actingAs($partnerUser);

        $this->getJson('/api/prm/partner/resources/collaterals')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.title', 'Alpha Brochure');

        $this->getJson('/api/prm/partner/resources/collaterals?resource_category=brochure')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        $this->getJson('/api/prm/partner/resources/collaterals?search=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        $this->getJson('/api/prm/partner/resources/collaterals?product_id='.$product->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        $this->postJson('/api/prm/partner/resources/collaterals/'.$visible->id.'/downloads')
            ->assertOk();

        $this->assertDatabaseHas('collateral_downloads', [
            'collateral_id' => $visible->id,
            'user_id' => $partnerUser->id,
            'partner_organization_id' => $partnerOrg->id,
        ]);

        $this->postJson('/api/prm/partner/resources/collaterals/'.$hiddenInactive->id.'/downloads')
            ->assertStatus(422);
    }

    public function test_reseller_sees_reseller_visible_resources(): void
    {
        $this->fakeEnterpriseStorage('local');
        [$tenant, $companyAdmin, , $product] = $this->tenantAdminPartnerProduct();

        $rootId = (int) Organization::query()->where('tenant_id', $tenant->id)->where('type', Organization::TYPE_COMPANY)->value('id');

        $resellerOrg = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $rootId,
            'type' => Organization::TYPE_RESELLER,
            'legal_name' => 'Reseller Legal',
            'display_name' => 'Reseller Co',
            'status' => Organization::STATUS_ACTIVE,
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        $onlyReseller = Collateral::query()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'name' => 'Reseller Only',
            'description' => null,
            'type' => 'doc',
            'file_key' => 'tenant/'.$tenant->id.'/collaterals/res-only.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 20,
            'partner_visible' => false,
            'reseller_visible' => true,
            'resource_category' => 'training',
            'status' => Collateral::STATUS_ACTIVE,
            'metadata' => null,
        ]);
        Storage::disk('local')->put($onlyReseller->file_key, 'x');

        $resellerUser = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Reseller Admin',
            'email' => 'res-admin-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_RESELLER_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        UserOrganizationAssignment::query()->create([
            'user_id' => $resellerUser->id,
            'organization_id' => $resellerOrg->id,
        ]);

        Sanctum::actingAs($resellerUser);

        $this->getJson('/api/prm/partner/resources/collaterals')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.title', 'Reseller Only');
    }

    public function test_partner_cannot_manage_admin_resources(): void
    {
        $this->fakeEnterpriseStorage('local');
        [$tenant, , $partnerOrg, $product] = $this->tenantAdminPartnerProduct();

        $partnerUser = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Partner User',
            'email' => 'partner-no-admin-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_PARTNER_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        UserOrganizationAssignment::query()->create([
            'user_id' => $partnerUser->id,
            'organization_id' => $partnerOrg->id,
        ]);

        Sanctum::actingAs($partnerUser);

        $file = UploadedFile::fake()->create('x.pdf', 20, 'application/pdf');
        $this->post('/api/prm/resources', [
            'title' => 'Hack',
            'resource_category' => 'x',
            'file' => $file,
            'partner_visible' => true,
            'reseller_visible' => false,
            'status' => 'active',
        ], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Organization, 3: Product}
     */
    private function tenantAdminPartnerProduct(): array
    {
        $tenant = Tenant::query()->create(['name' => 'T-Res', 'status' => Tenant::STATUS_ACTIVE]);
        $companyAdmin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'CA Res',
            'email' => 'ca-res-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $root = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Root',
            'display_name' => 'Root',
            'status' => Organization::STATUS_ACTIVE,
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $partnerOrg = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $root->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner',
            'display_name' => 'Partner',
            'status' => Organization::STATUS_ACTIVE,
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'name' => 'Widget',
            'sku' => 'W-1',
            'unit_price' => 10,
            'tax_rate' => 0,
            'status' => Product::STATUS_ACTIVE,
        ]);

        return [$tenant, $companyAdmin, $partnerOrg, $product];
    }
}
