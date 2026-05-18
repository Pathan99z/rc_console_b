<?php

namespace Tests\Feature\Cache;

use App\Models\Contact;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Cache\TenantListCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActorListCacheIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_cache_keys_differ_per_actor_in_same_tenant(): void
    {
        Cache::flush();
        $tenant = Tenant::factory()->create();
        $userA = User::factory()->create(['tenant_id' => $tenant->id, 'role' => Role::CODE_USER]);
        $userB = User::factory()->create(['tenant_id' => $tenant->id, 'role' => Role::CODE_USER]);

        $cache = app(TenantListCache::class);
        $filters = [];
        $keyA = $cache->buildKey($userA, 'contacts', $tenant->id, $filters, 15);
        $keyB = $cache->buildKey($userB, 'contacts', $tenant->id, $filters, 15);

        $this->assertNotSame($keyA, $keyB);
    }

    public function test_standard_user_does_not_receive_company_admin_cached_contact_list(): void
    {
        Cache::flush();
        $tenant = Tenant::factory()->create();
        $companyAdmin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => Role::CODE_COMPANY_ADMIN,
        ]);
        $standardUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => Role::CODE_USER,
        ]);

        Contact::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'AdminOnly',
            'last_name' => 'Lead',
            'email' => 'admin-only-'.uniqid('', true).'@example.com',
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'assigned_user_id' => $companyAdmin->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);

        Sanctum::actingAs($companyAdmin);
        $adminEmails = collect($this->getJson('/api/contacts')->assertOk()->json('data.items'))->pluck('email');

        Sanctum::actingAs($standardUser);
        $userEmails = collect($this->getJson('/api/contacts')->assertOk()->json('data.items'))->pluck('email');

        $this->assertTrue($adminEmails->contains(fn ($e) => str_contains((string) $e, 'admin-only-')));
        $this->assertFalse($userEmails->contains(fn ($e) => str_contains((string) $e, 'admin-only-')));
    }
}
