<?php

namespace Tests\Feature\Product;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_create_and_update_product(): void
    {
        [$tenant, $admin] = $this->createAdminContext();
        Sanctum::actingAs($admin);

        $productId = (int) $this->postJson('/api/products', [
            'name' => 'CRM License',
            'sku' => 'crm-001',
            'unit_price' => 1999.99,
            'tax_rate' => 18,
        ])->assertCreated()
            ->assertJsonPath('data.product.sku', 'CRM-001')
            ->json('data.product.id');

        $this->putJson("/api/products/{$productId}", [
            'unit_price' => 2499.99,
            'status' => 0,
        ])->assertOk()
            ->assertJsonPath('data.product.status', 'inactive');
    }

    public function test_standard_user_cannot_delete_product(): void
    {
        [$tenant, $admin] = $this->createAdminContext();
        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Sales User',
            'email' => 'product-user@example.com',
            'password' => 'secret123',
            'role' => 'user',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $productId = (int) $this->postJson('/api/products', [
            'name' => 'Starter Plan',
            'sku' => 'starter-01',
            'unit_price' => 499,
        ])->assertCreated()->json('data.product.id');

        Sanctum::actingAs($user);
        $this->deleteJson("/api/products/{$productId}")
            ->assertStatus(404);
    }

    public function test_delete_is_restricted_when_product_is_used_in_quote(): void
    {
        [$tenant, $admin] = $this->createAdminContext();
        Sanctum::actingAs($admin);

        $productId = (int) $this->postJson('/api/products', [
            'name' => 'Enterprise Plan',
            'sku' => 'ent-001',
            'unit_price' => 9999,
        ])->assertCreated()->json('data.product.id');

        Schema::create('quote_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->timestamps();
        });

        DB::table('quote_items')->insert([
            'product_id' => $productId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/api/products/{$productId}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product']);
    }

    public function test_tenant_isolation_is_enforced_for_product_access(): void
    {
        [$tenantA, $adminA] = $this->createAdminContext('Tenant A', 'product-admin-a@example.com');
        [$tenantB, $adminB] = $this->createAdminContext('Tenant B', 'product-admin-b@example.com');

        Sanctum::actingAs($adminA);
        $productId = (int) $this->postJson('/api/products', [
            'name' => 'Tenant A Product',
            'sku' => 'A-PROD',
            'unit_price' => 100,
        ])->assertCreated()->json('data.product.id');

        Sanctum::actingAs($adminB);
        $this->getJson("/api/products/{$productId}")
            ->assertStatus(404);
    }

    public function test_global_admin_can_filter_products_by_tenant(): void
    {
        [$tenantA, $adminA] = $this->createAdminContext('Tenant A', 'product-admin-ga-a@example.com');
        [$tenantB, $adminB] = $this->createAdminContext('Tenant B', 'product-admin-ga-b@example.com');

        $globalAdmin = User::query()->create([
            'tenant_id' => null,
            'name' => 'Global Admin',
            'email' => 'product-global-admin@example.com',
            'password' => 'secret123',
            'role' => 'global_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($adminA);
        $this->postJson('/api/products', [
            'name' => 'Tenant A Product',
            'sku' => 'GA-A',
            'unit_price' => 150,
        ])->assertCreated();

        Sanctum::actingAs($adminB);
        $this->postJson('/api/products', [
            'name' => 'Tenant B Product',
            'sku' => 'GA-B',
            'unit_price' => 250,
        ])->assertCreated();

        Sanctum::actingAs($globalAdmin);
        $response = $this->getJson("/api/products?tenant_id={$tenantA->id}");
        $response->assertOk();
        $names = collect($response->json('data.items'))->pluck('name');
        $this->assertTrue($names->contains('Tenant A Product'));
        $this->assertFalse($names->contains('Tenant B Product'));
    }

    private function createAdminContext(string $tenantName = 'Tenant', string $email = 'product-admin@example.com'): array
    {
        $tenant = Tenant::query()->create(['name' => $tenantName, 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Company Admin',
            'email' => $email,
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        return [$tenant, $admin];
    }
}
