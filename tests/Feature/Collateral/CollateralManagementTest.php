<?php

namespace Tests\Feature\Collateral;

use App\Mail\CollateralSharedMail;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CollateralManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_upload_collateral_with_product_association(): void
    {
        [$tenant, $admin, $user] = $this->createContext();
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'name' => 'CRM License',
            'unit_price' => 1000,
            'status' => Product::STATUS_ACTIVE,
        ]);

        Storage::fake('s3');
        config(['filesystems.default' => 's3']);
        Sanctum::actingAs($user);

        $response = $this->post('/api/collaterals', [
            'product_id' => $product->id,
            'name' => 'Sales Brochure',
            'type' => 'brochure',
            'file' => UploadedFile::fake()->create('brochure.pdf', 200, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.collateral.product_id', $product->id);
    }

    public function test_collateral_detail_returns_signed_url(): void
    {
        [$tenant, $admin] = $this->createAdminContext();
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'name' => 'CRM License',
            'unit_price' => 1000,
            'status' => Product::STATUS_ACTIVE,
        ]);

        Storage::fake('s3');
        config(['filesystems.default' => 's3']);
        Sanctum::actingAs($admin);
        $collateralId = (int) $this->post('/api/collaterals', [
            'product_id' => $product->id,
            'name' => 'Proposal Deck',
            'type' => 'proposal',
            'file' => UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.collateral.id');

        $this->getJson("/api/collaterals/{$collateralId}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['collateral' => ['signed_url']]]);
    }

    public function test_send_collateral_sends_email(): void
    {
        [$tenant, $admin, $user] = $this->createContext();
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'name' => 'CRM License',
            'unit_price' => 1000,
            'status' => Product::STATUS_ACTIVE,
        ]);

        Storage::fake('s3');
        config(['filesystems.default' => 's3']);
        Mail::fake();
        Sanctum::actingAs($user);

        $collateralId = (int) $this->post('/api/collaterals', [
            'product_id' => $product->id,
            'name' => 'Feature Sheet',
            'type' => 'brochure',
            'file' => UploadedFile::fake()->create('sheet.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.collateral.id');

        $this->postJson("/api/collaterals/{$collateralId}/send", [
            'email' => 'customer@example.com',
            'message' => 'Please review this document.',
        ])->assertOk();

        Mail::assertSent(CollateralSharedMail::class, function (CollateralSharedMail $mail): bool {
            return $mail->hasTo('customer@example.com');
        });
    }

    public function test_tenant_isolation_is_enforced_for_collateral_access(): void
    {
        [$tenantA, $adminA] = $this->createAdminContext('Tenant A', 'coll-admin-a@example.com');
        [$tenantB, $adminB] = $this->createAdminContext('Tenant B', 'coll-admin-b@example.com');

        $productA = Product::query()->create([
            'tenant_id' => $tenantA->id,
            'created_by_user_id' => $adminA->id,
            'updated_by_user_id' => $adminA->id,
            'name' => 'Tenant A Product',
            'unit_price' => 100,
            'status' => Product::STATUS_ACTIVE,
        ]);

        Storage::fake('s3');
        config(['filesystems.default' => 's3']);
        Sanctum::actingAs($adminA);
        $collateralId = (int) $this->post('/api/collaterals', [
            'product_id' => $productA->id,
            'name' => 'Tenant A Collateral',
            'type' => 'brochure',
            'file' => UploadedFile::fake()->create('a.pdf', 80, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.collateral.id');

        Sanctum::actingAs($adminB);
        $this->getJson("/api/collaterals/{$collateralId}")->assertStatus(404);
    }

    public function test_standard_user_cannot_delete_collateral(): void
    {
        [$tenant, $admin, $user] = $this->createContext();
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'name' => 'CRM License',
            'unit_price' => 1000,
            'status' => Product::STATUS_ACTIVE,
        ]);

        Storage::fake('s3');
        config(['filesystems.default' => 's3']);
        Sanctum::actingAs($admin);
        $collateralId = (int) $this->post('/api/collaterals', [
            'product_id' => $product->id,
            'name' => 'Delete Test',
            'type' => 'proposal',
            'file' => UploadedFile::fake()->create('delete.pdf', 80, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.collateral.id');

        Sanctum::actingAs($user);
        $this->deleteJson("/api/collaterals/{$collateralId}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['collateral']);
    }

    public function test_cross_tenant_product_mapping_is_rejected(): void
    {
        [$tenantA, $adminA] = $this->createAdminContext('Tenant A', 'cross-admin-a@example.com');
        [$tenantB, $adminB] = $this->createAdminContext('Tenant B', 'cross-admin-b@example.com');

        $productA = Product::query()->create([
            'tenant_id' => $tenantA->id,
            'created_by_user_id' => $adminA->id,
            'updated_by_user_id' => $adminA->id,
            'name' => 'Tenant A Product',
            'unit_price' => 200,
            'status' => Product::STATUS_ACTIVE,
        ]);

        Storage::fake('s3');
        config(['filesystems.default' => 's3']);
        Sanctum::actingAs($adminB);
        $this->post('/api/collaterals', [
            'product_id' => $productA->id,
            'name' => 'Cross Tenant Upload',
            'type' => 'brochure',
            'file' => UploadedFile::fake()->create('cross.pdf', 60, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['product_id']);
    }

    private function createContext(): array
    {
        [$tenant, $admin] = $this->createAdminContext();
        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Sales User',
            'email' => 'coll-user@example.com',
            'password' => 'secret123',
            'role' => 'user',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        return [$tenant, $admin, $user];
    }

    private function createAdminContext(string $tenantName = 'Tenant', string $email = 'coll-admin@example.com'): array
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
