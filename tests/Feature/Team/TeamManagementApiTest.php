<?php

namespace Tests\Feature\Team;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_manage_teams_in_tenant(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Company Admin',
            'email' => 'company-admin-team@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $create = $this->postJson('/api/teams', ['name' => 'Inside Sales']);
        $create->assertCreated();
        $teamId = (int) $create->json('data.team.id');

        $this->putJson("/api/teams/{$teamId}", ['name' => 'Enterprise Sales'])->assertOk();
        $this->getJson('/api/teams')->assertOk()->assertJsonPath('data.items.0.name', 'Enterprise Sales');
        $this->deleteJson("/api/teams/{$teamId}")->assertOk();
    }
}
