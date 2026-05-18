<?php

namespace Tests\Feature\Cache;

use App\Models\Contact;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Cache\CacheInvalidationService;
use App\Support\Cache\DashboardCacheManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_mutation_bumps_login_dashboard_version_for_tenant(): void
    {
        Cache::flush();
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => Role::CODE_COMPANY_ADMIN,
        ]);

        $manager = app(DashboardCacheManager::class);
        $before = $manager->loginDashboardVersion($admin);

        app(CacheInvalidationService::class)->afterCrmMutation($tenant->id, null);

        $after = $manager->loginDashboardVersion($admin);
        $this->assertNotSame($before, $after);
    }

    public function test_contact_create_invalidates_dashboard_epoch(): void
    {
        Cache::flush();
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => Role::CODE_COMPANY_ADMIN]);

        $epochKey = "dashboard:t:{$tenant->id}:epoch";
        Cache::put($epochKey, '1', 3600);

        Contact::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'A',
            'email' => 'inv@example.com',
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);

        app(CacheInvalidationService::class)->afterCrmMutation($tenant->id, null);

        $this->assertNotSame('1', (string) Cache::get($epochKey));
    }
}
