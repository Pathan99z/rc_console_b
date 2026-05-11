<?php

namespace Tests\Feature\Contact;

use App\Models\Tenant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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
        $assignee = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Company Assignee',
            'email' => 'company-assignee@example.com',
            'password' => 'secret123',
            'role' => 'user',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $companyResponse = $this->postJson('/api/companies', [
            'name' => 'Acme Labs',
            'industry' => 'Software',
            'company_type' => 'Enterprise',
            'employees' => 150,
            'revenue' => 5000000,
            'timezone' => 'Africa/Johannesburg',
            'linkedin_url' => 'https://linkedin.com/company/acme-labs',
            'address' => '123 Main Street',
            'city' => 'Johannesburg',
            'state' => 'GP',
            'postal_code' => '2001',
            'country' => 'South Africa',
            'description' => 'Leading technology company.',
            'email' => 'hello@acme.com',
            'assigned_user_id' => $assignee->id,
        ]);
        $companyResponse->assertCreated();
        $companyResponse->assertJsonPath('data.company.assigned_user.id', $assignee->id);
        $companyResponse->assertJsonPath('data.company.created_by_user.id', $admin->id);
        $companyResponse->assertJsonPath('data.company.industry', 'Software');
        $companyResponse->assertJsonPath('data.company.country', 'South Africa');
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

    public function test_company_assigned_user_must_belong_to_same_tenant(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenantA->id,
            'name' => 'Company Admin',
            'email' => 'company-admin-assigned@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $otherTenantUser = User::query()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Other Tenant User',
            'email' => 'other-tenant-user@example.com',
            'password' => 'secret123',
            'role' => 'user',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/companies', [
            'name' => 'Acme Labs',
            'assigned_user_id' => $otherTenantUser->id,
        ])->assertStatus(404);
    }

    public function test_company_import_and_export_work(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Company Admin',
            'email' => 'company-admin-import@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $csv = <<<CSV
name,industry,company_type,employees,revenue,phone,email,website,timezone,linkedin_url,address,city,state,postal_code,country,description,status
Acme One,Software,Enterprise,120,4000000,+27-11-123-4567,contact@acmeone.com,https://acmeone.com,Africa/Johannesburg,https://linkedin.com/company/acmeone,123 Main Street,Johannesburg,GP,2001,South Africa,Leading company,1
,Software,Enterprise,50,1000000,+27-11-000-0000,skip@example.com,https://skip.com,Africa/Johannesburg,https://linkedin.com/company/skip,1 Skip Street,Johannesburg,GP,2001,South Africa,Should skip,1
CSV;
        $file = UploadedFile::fake()->createWithContent('companies.csv', $csv);

        $this->postJson('/api/companies/import', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.skipped', 1);

        $export = $this->get('/api/companies/export');
        $export->assertOk();
        $this->assertStringContainsString('Acme One', $export->streamedContent());
    }

    public function test_attach_and_detach_company_endpoints_work(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Company Admin',
            'email' => 'company-admin-attach@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $company = $this->postJson('/api/companies', [
            'name' => 'Attach Co',
        ])->assertCreated()->json('data.company');

        $contact = $this->postJson('/api/contacts', [
            'first_name' => 'Attach',
            'last_name' => 'Target',
        ])->assertCreated()->json('data.contact');

        $this->postJson('/api/contacts/'.$contact['id'].'/attach-company', [
            'company_id' => $company['id'],
        ])
            ->assertOk()
            ->assertJsonPath('data.contact.company.id', $company['id']);

        $this->postJson('/api/contacts/'.$contact['id'].'/detach-company')
            ->assertOk()
            ->assertJsonPath('data.contact.company', null);
    }

    public function test_contact_email_must_be_unique_per_tenant(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Company Admin',
            'email' => 'company-admin-unique-contact@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/contacts', [
            'first_name' => 'One',
            'email' => 'dup-contact@example.com',
        ])->assertCreated();

        $this->postJson('/api/contacts', [
            'first_name' => 'Two',
            'email' => 'dup-contact@example.com',
        ])->assertStatus(422);
    }

    public function test_company_email_must_be_unique_per_tenant(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Company Admin',
            'email' => 'company-admin-unique-company@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/companies', [
            'name' => 'One',
            'email' => 'dup-company@example.com',
        ])->assertCreated();

        $this->postJson('/api/companies', [
            'name' => 'Two',
            'email' => 'dup-company@example.com',
        ])->assertStatus(422);
    }

    public function test_company_admin_can_fetch_single_company_detail(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Company Admin',
            'email' => 'company-admin-show@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $companyId = (int) $this->postJson('/api/companies', [
            'name' => 'Detail Company',
            'email' => 'detail-company@example.com',
        ])->assertCreated()->json('data.company.id');

        $this->getJson("/api/companies/{$companyId}")
            ->assertOk()
            ->assertJsonPath('data.company.id', $companyId)
            ->assertJsonPath('data.company.name', 'Detail Company');
    }

    public function test_company_detail_is_tenant_isolated(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'status' => Tenant::STATUS_ACTIVE]);
        $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'status' => Tenant::STATUS_ACTIVE]);

        $adminA = User::query()->create([
            'tenant_id' => $tenantA->id,
            'name' => 'Admin A',
            'email' => 'admin-a-show@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $adminB = User::query()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Admin B',
            'email' => 'admin-b-show@example.com',
            'password' => 'secret123',
            'role' => 'company_admin',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($adminA);
        $companyId = (int) $this->postJson('/api/companies', [
            'name' => 'Tenant A Company',
        ])->assertCreated()->json('data.company.id');

        Sanctum::actingAs($adminB);
        $this->getJson("/api/companies/{$companyId}")->assertStatus(404);
    }
}
