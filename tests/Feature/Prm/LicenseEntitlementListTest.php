<?php

namespace Tests\Feature\Prm;

use App\Models\LicenseEntitlement;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LicenseEntitlementListTest extends TestCase
{
    use RefreshDatabase;

    public function test_license_entitlements_index_includes_holder_and_product_summaries(): void
    {
        $partnerDisplayName = 'Partner Co';
        [$tenant, $companyAdmin, $partnerOrg] = $this->tenantWithPartner($partnerDisplayName);

        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'name' => 'Enterprise License',
            'sku' => 'ENT-LIC',
            'unit_price' => 100,
            'tax_rate' => 0,
            'status' => Product::STATUS_ACTIVE,
        ]);

        LicenseEntitlement::query()->create([
            'tenant_id' => $tenant->id,
            'holder_organization_id' => $partnerOrg->id,
            'parent_entitlement_id' => null,
            'product_id' => $product->id,
            'units_total' => 5,
            'units_consumed' => 2,
            'notes' => null,
            'created_by_user_id' => $companyAdmin->id,
            'metadata' => null,
        ]);

        Sanctum::actingAs($companyAdmin);

        $this->getJson('/api/prm/license-entitlements?per_page=15')
            ->assertOk()
            ->assertJsonPath('data.items.0.holder_organization_id', $partnerOrg->id)
            ->assertJsonPath('data.items.0.product_id', $product->id)
            ->assertJsonPath('data.items.0.units_available', 3)
            ->assertJsonPath('data.items.0.holder_organization.display_name', $partnerDisplayName)
            ->assertJsonPath('data.items.0.holder_organization.type', Organization::TYPE_PARTNER)
            ->assertJsonPath('data.items.0.product.name', 'Enterprise License')
            ->assertJsonPath('data.items.0.product.sku', 'ENT-LIC');
    }

    public function test_allocate_response_includes_holder_and_product_summaries(): void
    {
        $partnerDisplayName = 'Partner Co';
        [$tenant, $companyAdmin, $partnerOrg] = $this->tenantWithPartner($partnerDisplayName);

        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'name' => 'Seat Pack',
            'sku' => 'SEAT-10',
            'unit_price' => 50,
            'tax_rate' => 0,
            'status' => Product::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($companyAdmin);

        $this->postJson('/api/prm/license-entitlements', [
            'holder_organization_id' => $partnerOrg->id,
            'product_id' => $product->id,
            'units_total' => 10,
        ])->assertCreated()
            ->assertJsonPath('data.entitlement.units_total', 10)
            ->assertJsonPath('data.entitlement.holder_organization.display_name', $partnerDisplayName)
            ->assertJsonPath('data.entitlement.product.name', 'Seat Pack');
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Organization}
     */
    private function tenantWithPartner(string $partnerDisplayName = 'Partner Co'): array
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant Lic', 'status' => Tenant::STATUS_ACTIVE]);
        $companyAdmin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'CA Lic',
            'email' => 'ca-lic-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $root = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Root Legal',
            'display_name' => 'Root Co',
            'status' => Organization::STATUS_ACTIVE,
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $partnerOrg = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $root->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner Legal',
            'display_name' => $partnerDisplayName,
            'status' => Organization::STATUS_ACTIVE,
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        return [$tenant, $companyAdmin, $partnerOrg];
    }
}
