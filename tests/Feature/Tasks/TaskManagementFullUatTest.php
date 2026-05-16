<?php

namespace Tests\Feature\Tasks;

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserOrganizationAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskManagementFullUatTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_full_task_lifecycle(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['companyAdmin']);

        $internal = $this->makeUser($ctx['tenant']->id, Role::CODE_USER, 'internal@example.com', null);
        $partnerUser = $this->makeUser($ctx['tenant']->id, Role::CODE_PARTNER_SALES_CONSULTANT, 'pu@example.com', $ctx['partner']->id);
        $resellerUser = $this->makeUser($ctx['tenant']->id, Role::CODE_RESELLER_SALES_CONSULTANT, 'ru@example.com', $ctx['reseller']->id);

        $created = $this->postJson('/api/tasks', [
            'title' => 'Internal follow-up',
            'priority' => Task::PRIORITY_HIGH,
            'assignee_user_id' => $internal->id,
        ])->assertCreated()->json('data.task.id');

        $this->patchJson("/api/tasks/{$created}", [
            'scope_organization_id' => $ctx['partner']->id,
        ])->assertOk();

        $this->postJson("/api/tasks/{$created}/assign", [
            'assignee_user_id' => $partnerUser->id,
        ])->assertOk();

        $this->patchJson("/api/tasks/{$created}", [
            'scope_organization_id' => $ctx['reseller']->id,
        ])->assertOk();

        $this->postJson("/api/tasks/{$created}/assign", [
            'assignee_user_id' => $resellerUser->id,
        ])->assertOk();

        $this->postJson("/api/tasks/{$created}/start")->assertOk();
        $this->postJson("/api/tasks/{$created}/complete")->assertOk()
            ->assertJsonPath('data.task.status', Task::STATUS_COMPLETED);

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'tasks',
            'action' => 'tasks.create',
            'entity_id' => $created,
        ]);
    }

    public function test_partner_admin_assignment_rules(): void
    {
        $ctx = $this->seedHierarchy();
        $partnerUser = $this->makeUser($ctx['tenant']->id, Role::CODE_PARTNER_SALES_MANAGER, 'pm@example.com', $ctx['partner']->id);
        $resellerUser = $this->makeUser($ctx['tenant']->id, Role::CODE_RESELLER_SALES_CONSULTANT, 'rc@example.com', $ctx['reseller']->id);
        $siblingUser = $this->makeUser($ctx['tenant']->id, Role::CODE_PARTNER_SALES_CONSULTANT, 'sib@example.com', $ctx['siblingPartner']->id);
        $internal = $this->makeUser($ctx['tenant']->id, Role::CODE_USER, 'int2@example.com', null);

        Sanctum::actingAs($ctx['partnerAdmin']);

        $taskId = $this->postJson('/api/tasks', [
            'title' => 'Partner task',
            'scope_organization_id' => $ctx['reseller']->id,
            'assignee_user_id' => $resellerUser->id,
            'priority' => Task::PRIORITY_MEDIUM,
        ])->assertCreated()->json('data.task.id');

        $this->postJson('/api/tasks', [
            'title' => 'Partner local',
            'scope_organization_id' => $ctx['partner']->id,
            'assignee_user_id' => $partnerUser->id,
        ])->assertCreated();

        $this->postJson("/api/tasks/{$taskId}/assign", [
            'assignee_user_id' => $siblingUser->id,
        ])->assertStatus(422);

        $this->postJson('/api/tasks', [
            'title' => 'Blocked internal',
            'scope_organization_id' => $ctx['partner']->id,
            'assignee_user_id' => $internal->id,
        ])->assertStatus(422);
    }

    public function test_reseller_admin_assignment_rules(): void
    {
        $ctx = $this->seedHierarchy();
        $resellerUser = $this->makeUser($ctx['tenant']->id, Role::CODE_RESELLER_SALES_CONSULTANT, 'ru2@example.com', $ctx['reseller']->id);
        $partnerUser = $this->makeUser($ctx['tenant']->id, Role::CODE_PARTNER_SALES_CONSULTANT, 'pu2@example.com', $ctx['partner']->id);
        $siblingResellerUser = $this->makeUser($ctx['tenant']->id, Role::CODE_RESELLER_SALES_CONSULTANT, 'sr@example.com', $ctx['siblingReseller']->id);

        Sanctum::actingAs($ctx['resellerAdmin']);

        $taskId = $this->postJson('/api/tasks', [
            'title' => 'Reseller task',
            'scope_organization_id' => $ctx['reseller']->id,
            'assignee_user_id' => $resellerUser->id,
        ])->assertCreated()->json('data.task.id');

        $this->postJson("/api/tasks/{$taskId}/assign", [
            'assignee_user_id' => $partnerUser->id,
        ])->assertStatus(422);

        $this->postJson("/api/tasks/{$taskId}/assign", [
            'assignee_user_id' => $siblingResellerUser->id,
        ])->assertStatus(422);
    }

    public function test_consultant_self_task_and_visibility(): void
    {
        $ctx = $this->seedHierarchy();
        $consultant = $this->makeUser($ctx['tenant']->id, Role::CODE_RESELLER_SALES_CONSULTANT, 'con@example.com', $ctx['reseller']->id);

        Sanctum::actingAs($consultant);

        $taskId = $this->postJson('/api/tasks', [
            'title' => 'My follow-up',
            'scope_organization_id' => $ctx['reseller']->id,
            'assignee_user_id' => $consultant->id,
        ])->assertCreated()->json('data.task.id');

        $this->getJson('/api/tasks?view=my')->assertOk()
            ->assertJsonPath('data.items.0.id', $taskId);

        $this->getJson('/api/tasks?view=tenant')->assertStatus(422);

        Sanctum::actingAs($ctx['companyAdmin']);
        Task::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'scope_organization_id' => $ctx['reseller']->id,
            'title' => 'Hidden from consultant',
            'priority' => Task::PRIORITY_LOW,
            'status' => Task::STATUS_PENDING,
            'assignee_user_id' => $ctx['resellerAdmin']->id,
            'created_by_user_id' => $ctx['resellerAdmin']->id,
        ]);

        Sanctum::actingAs($consultant);
        $this->getJson('/api/tasks?view=my')->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    public function test_related_entities_and_unauthorized_related_blocked(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['companyAdmin']);

        $contact = Contact::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'channel_organization_id' => $ctx['reseller']->id,
            'first_name' => 'Ann',
            'last_name' => 'Bee',
            'email' => 'ann@example.com',
            'created_by_user_id' => $ctx['companyAdmin']->id,
            'updated_by_user_id' => $ctx['companyAdmin']->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);

        $pipeline = \App\Models\Pipeline::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'created_by_user_id' => $ctx['companyAdmin']->id,
            'name' => 'Pipe',
            'status' => \App\Models\Pipeline::STATUS_ACTIVE,
        ]);
        $stage = \App\Models\PipelineStage::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'S1',
            'stage_order' => 1,
            'status' => \App\Models\PipelineStage::STATUS_ACTIVE,
        ]);

        $deal = Deal::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'channel_organization_id' => $ctx['reseller']->id,
            'partner_organization_id' => $ctx['reseller']->id,
            'contact_id' => $contact->id,
            'name' => 'Big Deal',
            'owner_user_id' => $ctx['companyAdmin']->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $stage->id,
            'created_by_user_id' => $ctx['companyAdmin']->id,
            'updated_by_user_id' => $ctx['companyAdmin']->id,
            'status' => Deal::STATUS_WON,
        ]);

        $quote = Quote::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'channel_organization_id' => $ctx['reseller']->id,
            'deal_id' => $deal->id,
            'contact_id' => $contact->id,
            'created_by_user_id' => $ctx['companyAdmin']->id,
            'updated_by_user_id' => $ctx['companyAdmin']->id,
            'quote_number' => 'Q-TASK-1',
            'public_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'status' => Quote::STATUS_ACCEPTED,
            'payment_status' => Quote::PAYMENT_STATUS_PAID,
            'total' => 500,
            'currency_code' => 'ZAR',
        ]);

        $this->postJson('/api/tasks', [
            'title' => 'Contact task',
            'related_type' => Task::RELATED_CONTACT,
            'related_id' => $contact->id,
            'assignee_user_id' => $ctx['companyAdmin']->id,
        ])->assertCreated()
            ->assertJsonPath('data.task.related.summary', 'Ann Bee');

        $this->postJson('/api/tasks', [
            'title' => 'Deal task',
            'related_type' => Task::RELATED_DEAL,
            'related_id' => $deal->id,
            'assignee_user_id' => $ctx['companyAdmin']->id,
        ])->assertCreated()
            ->assertJsonPath('data.task.related.summary', 'Big Deal');

        $this->postJson('/api/tasks', [
            'title' => 'Quote task',
            'related_type' => Task::RELATED_QUOTE,
            'related_id' => $quote->id,
            'assignee_user_id' => $ctx['companyAdmin']->id,
        ])->assertCreated()
            ->assertJsonPath('data.task.related.summary', 'Q-TASK-1');

        Sanctum::actingAs($ctx['siblingPartnerAdmin']);
        $this->postJson('/api/tasks', [
            'title' => 'Blocked deal',
            'scope_organization_id' => $ctx['siblingPartner']->id,
            'related_type' => Task::RELATED_DEAL,
            'related_id' => $deal->id,
            'assignee_user_id' => $ctx['siblingPartnerAdmin']->id,
        ])->assertNotFound();
    }

    public function test_filters_and_overdue(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['companyAdmin']);

        Task::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'title' => 'Overdue item',
            'priority' => Task::PRIORITY_HIGH,
            'status' => Task::STATUS_PENDING,
            'due_at' => now()->subDay(),
            'created_by_user_id' => $ctx['companyAdmin']->id,
            'assignee_user_id' => $ctx['companyAdmin']->id,
        ]);

        Task::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'title' => 'Done item',
            'priority' => Task::PRIORITY_LOW,
            'status' => Task::STATUS_COMPLETED,
            'completed_at' => now(),
            'created_by_user_id' => $ctx['companyAdmin']->id,
            'assignee_user_id' => $ctx['companyAdmin']->id,
        ]);

        $this->getJson('/api/tasks?view=tenant&overdue=1')->assertOk()
            ->assertJsonPath('data.items.0.is_overdue', true);

        $this->getJson('/api/tasks?view=tenant&status=completed')->assertOk()
            ->assertJsonPath('data.items.0.status', Task::STATUS_COMPLETED);

        $this->getJson('/api/tasks?view=created_by_me')->assertOk();
    }

    public function test_tenant_isolation_and_idor(): void
    {
        $ctxA = $this->seedHierarchy();
        $ctxB = $this->seedHierarchy('Other Tenant');

        Sanctum::actingAs($ctxA['companyAdmin']);
        $taskId = $this->postJson('/api/tasks', [
            'title' => 'Tenant A task',
            'assignee_user_id' => $ctxA['companyAdmin']->id,
        ])->assertCreated()->json('data.task.id');

        Sanctum::actingAs($ctxB['companyAdmin']);
        $this->getJson("/api/tasks/{$taskId}")->assertNotFound();
    }

    public function test_assignable_users_endpoint(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['partnerAdmin']);

        $this->getJson('/api/tasks/assignable-users')->assertOk()
            ->assertJsonStructure(['data' => ['users' => [['id', 'name', 'email', 'role']]]]);
    }

    public function test_regression_smoke_existing_modules(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['companyAdmin']);

        $this->getJson('/api/contacts')->assertOk();
        $this->getJson('/api/deals')->assertOk();
        $this->getJson('/api/prm/commission-accruals')->assertOk();
        $this->getJson('/api/prm/payouts')->assertOk();
        $this->getJson("/api/organizations/{$ctx['reseller']->id}/dashboard")->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function seedHierarchy(?string $tenantName = 'Task Tenant'): array
    {
        $tenant = Tenant::query()->create(['name' => $tenantName, 'status' => Tenant::STATUS_ACTIVE]);
        $companyAdmin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'CA',
            'email' => 'ca-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $company = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Co',
            'display_name' => 'Co',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $partner = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner',
            'display_name' => 'Partner',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $siblingPartner = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Sibling Partner',
            'display_name' => 'Sibling Partner',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $reseller = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $partner->id,
            'type' => Organization::TYPE_RESELLER,
            'channel_mode' => Organization::CHANNEL_MODE_PARTNER_MANAGED,
            'legal_name' => 'Reseller',
            'display_name' => 'Reseller',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $siblingReseller = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $siblingPartner->id,
            'type' => Organization::TYPE_RESELLER,
            'channel_mode' => Organization::CHANNEL_MODE_PARTNER_MANAGED,
            'legal_name' => 'Sibling Reseller',
            'display_name' => 'Sibling Reseller',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        $partnerAdmin = $this->makeUser($tenant->id, Role::CODE_PARTNER_ADMIN, 'pa-'.uniqid('', true).'@example.com', $partner->id);
        $siblingPartnerAdmin = $this->makeUser($tenant->id, Role::CODE_PARTNER_ADMIN, 'spa-'.uniqid('', true).'@example.com', $siblingPartner->id);
        $resellerAdmin = $this->makeUser($tenant->id, Role::CODE_RESELLER_ADMIN, 'ra-'.uniqid('', true).'@example.com', $reseller->id);

        return compact(
            'tenant',
            'companyAdmin',
            'company',
            'partner',
            'siblingPartner',
            'reseller',
            'siblingReseller',
            'partnerAdmin',
            'siblingPartnerAdmin',
            'resellerAdmin',
        );
    }

    private function makeUser(int $tenantId, string $role, string $email, ?int $orgId): User
    {
        $user = User::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'U '.$email,
            'email' => $email,
            'password' => 'secret123',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        if ($orgId !== null) {
            UserOrganizationAssignment::query()->create([
                'user_id' => $user->id,
                'organization_id' => $orgId,
            ]);
        }

        return $user->fresh(['organizationAssignment.organization']);
    }
}
