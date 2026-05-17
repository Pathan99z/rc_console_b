<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\InAppNotification;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\Notifications\InAppNotificationTemplateKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Concerns\BuildsTenantUsersForNotifications;
use Tests\TestCase;

/**
 * Validates allocation + activation catalogue keys enforced by PRM workflows.
 */
final class LicenseNotificationTest extends TestCase
{
    use BuildsTenantUsersForNotifications;
    use RefreshDatabase;

    public function test_allocate_entitlement_writes_licenses_allocated_reseller_audience_records(): void
    {
        $tenant = $this->makeActiveTenant();
        $admin = $this->makeCompanyAdmin($tenant);
        $h = $this->seedCompanyPartnerResellerHierarchy($tenant, $admin);
        Sanctum::actingAs($admin);

        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'name' => 'SKU Lic',
            'sku' => 'LIC-GEN-'.uniqid(),
            'unit_price' => 700,
            'tax_rate' => 0,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $rid = $h['reseller']->id;

        $this->postJson('/api/prm/license-entitlements', [
            'holder_organization_id' => $rid,
            'product_id' => $product->id,
            'units_total' => 4,
        ])->assertCreated();

        $this->assertGreaterThanOrEqual(
            1,
            InAppNotification::query()
                ->where('tenant_id', $tenant->id)
                ->where('notification_type', InAppNotificationTemplateKeys::LICENSES_ALLOCATED_RESELLER)
                ->count(),
        );
    }

    public function test_allocate_partner_pool_writes_licenses_allocated_partner_audience_records(): void
    {
        $tenant = $this->makeActiveTenant();
        $admin = $this->makeCompanyAdmin($tenant);
        $h = $this->seedCompanyPartnerResellerHierarchy($tenant, $admin);
        Sanctum::actingAs($admin);

        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'name' => 'SKU Par',
            'sku' => 'LIC-P-'.uniqid(),
            'unit_price' => 300,
            'tax_rate' => 0,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $pid = $h['partner']->id;

        $this->postJson('/api/prm/license-entitlements', [
            'holder_organization_id' => $pid,
            'product_id' => $product->id,
            'units_total' => 2,
        ])->assertCreated();

        $this->assertGreaterThanOrEqual(
            1,
            InAppNotification::query()
                ->where('tenant_id', $tenant->id)
                ->where('notification_type', InAppNotificationTemplateKeys::LICENSES_ALLOCATED_PARTNER)
                ->count(),
        );
    }

    public function test_activate_license_writes_license_activated(): void
    {
        $tenant = $this->makeActiveTenant();
        $admin = $this->makeCompanyAdmin($tenant);
        $h = $this->seedCompanyPartnerResellerHierarchy($tenant, $admin);
        Sanctum::actingAs($admin);

        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'name' => 'SKU Act',
            'sku' => 'LIC-A-'.uniqid(),
            'unit_price' => 50,
            'tax_rate' => 0,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $rid = $h['reseller']->id;

        $eid = (int) $this->postJson('/api/prm/license-entitlements', [
            'holder_organization_id' => $rid,
            'product_id' => $product->id,
            'units_total' => 3,
        ])->assertCreated()->json('data.entitlement.id');

        $resellerAdmin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'RAL',
            'email' => 'ral-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_RESELLER_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $this->assignUserOrganization($resellerAdmin, $rid);

        Sanctum::actingAs($resellerAdmin);

        $this->postJson("/api/prm/license-entitlements/{$eid}/activate", [
            'units' => 1,
        ])->assertOk();

        $this->assertGreaterThanOrEqual(
            1,
            InAppNotification::query()
                ->where('tenant_id', $tenant->id)
                ->where('notification_type', InAppNotificationTemplateKeys::LICENSES_ACTIVATED)
                ->count(),
        );
    }
}
