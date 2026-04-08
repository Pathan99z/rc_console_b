<?php

namespace Tests\Feature\Contact;

use App\Models\Tenant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_create_company_and_contact(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Company Admin',
            'email' => 'company-admin-contact@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $companyResponse = $this->postJson('/api/companies', [
            'name' => 'Acme Labs',
            'email' => 'hello@acme.com',
        ]);
        $companyResponse->assertCreated();
        $companyId = (int) $companyResponse->json('data.company.id');

        $this->postJson('/api/contacts', [
            'first_name' => 'John',
            'last_name' => 'Smith',
            'email' => 'john.smith@acme.com',
            'company_id' => $companyId,
        ])->assertCreated();

        $this->assertDatabaseHas('contacts', [
            'tenant_id' => $tenant->id,
            'first_name' => 'John',
            'company_id' => $companyId,
        ]);
    }

    public function test_standard_user_visibility_is_scoped_to_own_or_team_contacts(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $lead = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Lead',
            'email' => 'lead@example.com',
            'password' => 'secret123',
            'role' => 'user',
            'data_scope' => 2,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $team = Team::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Sales Team',
            'status' => 1,
        ]);
        $lead->update(['team_id' => $team->id]);
        $member = User::query()->create([
            'tenant_id' => $tenant->id,
            'team_id' => $team->id,
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => 'secret123',
            'role' => 'user',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $other = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Other',
            'email' => 'other@example.com',
            'password' => 'secret123',
            'role' => 'user',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($lead);
        $this->postJson('/api/contacts', ['first_name' => 'Mine'])->assertCreated();
        $this->postJson('/api/contacts', ['first_name' => 'Team', 'assigned_user_id' => $member->id])->assertCreated();
        Sanctum::actingAs($other);
        $this->postJson('/api/contacts', ['first_name' => 'Other', 'assigned_user_id' => $other->id])->assertCreated();

        Sanctum::actingAs($lead);
        $response = $this->getJson('/api/contacts');
        $response->assertOk();
        $names = collect($response->json('data.items'))->pluck('first_name');
        $this->assertTrue($names->contains('Mine'));
        $this->assertTrue($names->contains('Team'));
        $this->assertFalse($names->contains('Other'));
    }

    public function test_contact_activity_and_lifecycle_update_work(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'User',
            'email' => 'activity-user@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $contactResponse = $this->postJson('/api/contacts', ['first_name' => 'Lifecycle']);
        $contactResponse->assertCreated();
        $contactId = (int) $contactResponse->json('data.contact.id');

        $this->putJson("/api/contacts/{$contactId}", ['lifecycle_stage' => 2])->assertOk();
        $this->postJson("/api/contacts/{$contactId}/activities", ['note' => 'Followed up by phone'])->assertOk();

        $this->assertDatabaseHas('contacts', ['id' => $contactId, 'lifecycle_stage' => 2]);
        $this->assertDatabaseHas('contact_activities', ['contact_id' => $contactId, 'note' => 'Followed up by phone']);
        $this->assertDatabaseHas('contacts', ['id' => $contactId, 'updated_by_user_id' => $user->id]);
    }
}
