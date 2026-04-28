<?php

namespace Tests\Feature\Deal;

use App\Models\Tenant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealPipelineManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_create_pipeline_stage_and_deal(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'deal-admin@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $pipelineId = (int) $this->postJson('/api/pipelines', ['name' => 'Sales Pipeline'])
            ->assertCreated()
            ->json('data.pipeline.id');

        $stageId = (int) $this->postJson("/api/pipelines/{$pipelineId}/stages", [
            'name' => 'Lead In',
            'stage_order' => 1,
        ])->assertCreated()->json('data.stage.id');

        $contactId = (int) $this->postJson('/api/contacts', [
            'first_name' => 'Deal',
            'email' => 'deal-contact@example.com',
        ])->assertCreated()->json('data.contact.id');

        $this->postJson('/api/deals', [
            'name' => 'New Opportunity',
            'contact_id' => $contactId,
            'owner_user_id' => $admin->id,
            'pipeline_id' => $pipelineId,
            'pipeline_stage_id' => $stageId,
            'estimated_value' => 5000,
            'currency_code' => 'USD',
            'probability' => 35,
            'expected_close_date' => now()->addDays(30)->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.deal.status', 'open')
            ->assertJsonPath('data.deal.currency_code', 'USD')
            ->assertJsonPath('data.deal.probability', 35);
    }

    public function test_standard_user_team_scope_can_see_team_deals(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $team = Team::query()->create(['tenant_id' => $tenant->id, 'name' => 'Sales Team', 'status' => 1]);
        $lead = User::query()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $team->id,
            'name' => 'Lead',
            'email' => 'deal-lead@example.com',
            'password' => 'secret123',
            'role' => 'user',
            'data_scope' => 2,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $member = User::query()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $team->id,
            'name' => 'Member',
            'email' => 'deal-member@example.com',
            'password' => 'secret123',
            'role' => 'user',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        // Use admin to bootstrap pipeline/stage/contact
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'deal-admin-team@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($admin);
        $pipelineId = (int) $this->postJson('/api/pipelines', ['name' => 'Sales Pipeline'])->assertCreated()->json('data.pipeline.id');
        $stageId = (int) $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Demo', 'stage_order' => 1])->assertCreated()->json('data.stage.id');
        $contactId = (int) $this->postJson('/api/contacts', ['first_name' => 'Deal Team', 'email' => 'deal-team@example.com'])->assertCreated()->json('data.contact.id');
        $this->postJson('/api/deals', [
            'name' => 'Team Deal',
            'contact_id' => $contactId,
            'owner_user_id' => $member->id,
            'pipeline_id' => $pipelineId,
            'pipeline_stage_id' => $stageId,
        ])->assertCreated();

        Sanctum::actingAs($lead);
        $response = $this->getJson('/api/deals');
        $response->assertOk();
        $names = collect($response->json('data.items'))->pluck('name');
        $this->assertTrue($names->contains('Team Deal'));
    }

    public function test_deal_stage_move_and_status_update_are_tracked(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'deal-admin-history@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $pipelineId = (int) $this->postJson('/api/pipelines', ['name' => 'Enterprise Pipeline'])->assertCreated()->json('data.pipeline.id');
        $stageOneId = (int) $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Demo', 'stage_order' => 1])->assertCreated()->json('data.stage.id');
        $stageTwoId = (int) $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Negotiation', 'stage_order' => 2])->assertCreated()->json('data.stage.id');
        $contactId = (int) $this->postJson('/api/contacts', ['first_name' => 'Deal Hist', 'email' => 'deal-hist@example.com'])->assertCreated()->json('data.contact.id');
        $dealId = (int) $this->postJson('/api/deals', [
            'name' => 'History Deal',
            'contact_id' => $contactId,
            'owner_user_id' => $admin->id,
            'pipeline_id' => $pipelineId,
            'pipeline_stage_id' => $stageOneId,
        ])->assertCreated()->json('data.deal.id');

        $this->postJson("/api/deals/{$dealId}/move-stage", ['pipeline_stage_id' => $stageTwoId])->assertOk();
        $this->patchJson("/api/deals/{$dealId}/status", ['status' => 'won'])->assertOk();
        $this->getJson("/api/deals/{$dealId}")
            ->assertOk()
            ->assertJsonPath('data.deal.status', 'won');
    }

    public function test_deal_currency_code_is_persisted_on_update(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'deal-admin-currency@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $pipelineId = (int) $this->postJson('/api/pipelines', ['name' => 'Currency Pipeline'])->assertCreated()->json('data.pipeline.id');
        $stageId = (int) $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Lead', 'stage_order' => 1])->assertCreated()->json('data.stage.id');
        $contactId = (int) $this->postJson('/api/contacts', ['first_name' => 'Currency', 'email' => 'deal-currency@example.com'])->assertCreated()->json('data.contact.id');
        $dealId = (int) $this->postJson('/api/deals', [
            'name' => 'Currency Deal',
            'contact_id' => $contactId,
            'owner_user_id' => $admin->id,
            'pipeline_id' => $pipelineId,
            'pipeline_stage_id' => $stageId,
            'estimated_value' => 1000,
        ])->assertCreated()->json('data.deal.id');

        $this->putJson("/api/deals/{$dealId}", [
            'currency_code' => 'inr',
            'probability' => 80,
        ])->assertOk()
            ->assertJsonPath('data.deal.currency_code', 'INR')
            ->assertJsonPath('data.deal.probability', 80);
    }

    public function test_deal_probability_validation_is_enforced(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'deal-admin-probability@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $pipelineId = (int) $this->postJson('/api/pipelines', ['name' => 'Probability Pipeline'])->assertCreated()->json('data.pipeline.id');
        $stageId = (int) $this->postJson("/api/pipelines/{$pipelineId}/stages", ['name' => 'Lead', 'stage_order' => 1])->assertCreated()->json('data.stage.id');
        $contactId = (int) $this->postJson('/api/contacts', ['first_name' => 'Probability', 'email' => 'deal-probability@example.com'])->assertCreated()->json('data.contact.id');

        $this->postJson('/api/deals', [
            'name' => 'Probability Deal',
            'contact_id' => $contactId,
            'owner_user_id' => $admin->id,
            'pipeline_id' => $pipelineId,
            'pipeline_stage_id' => $stageId,
            'probability' => 120,
        ])->assertStatus(422)->assertJsonValidationErrors(['probability']);
    }
}
