<?php

namespace Tests\Feature\Dashboard;

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\InAppNotification;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserOrganizationAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Concerns\BuildsTenantUsersForNotifications;
use Tests\TestCase;

class LoginDashboardTest extends TestCase
{
    use BuildsTenantUsersForNotifications;
    use RefreshDatabase;

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_global_admin_dashboard_returns_platform_kpis(): void
    {
        Cache::flush();
        $globalAdmin = $this->makeGlobalAdmin();
        $tenant = $this->makeActiveTenant();
        $companyAdmin = $this->makeCompanyAdmin($tenant);
        $this->seedCompanyPartnerResellerHierarchy($tenant, $companyAdmin);

        Sanctum::actingAs($globalAdmin);
        $response = $this->getJson('/api/dashboard')->assertOk();

        $response->assertJsonPath('data.dashboard_profile', Role::CODE_GLOBAL_ADMIN);
        $this->assertArrayHasKey('total_tenants', $response->json('data.kpis'));
        $this->assertArrayHasKey('revenue_summary', $response->json('data.kpis'));
        $this->assertArrayHasKey('onboarding_summary', $response->json('data.kpis'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.kpis.total_tenants'));
    }

    public function test_company_admin_dashboard_is_tenant_scoped(): void
    {
        Cache::flush();
        $tenantA = $this->makeActiveTenant('Tenant A');
        $adminA = $this->makeCompanyAdmin($tenantA);
        Contact::query()->create([
            'tenant_id' => $tenantA->id,
            'first_name' => 'A',
            'email' => 'a-login@example.com',
            'created_by_user_id' => $adminA->id,
            'updated_by_user_id' => $adminA->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);

        $tenantB = $this->makeActiveTenant('Tenant B');
        $adminB = $this->makeCompanyAdmin($tenantB);
        Contact::query()->create([
            'tenant_id' => $tenantB->id,
            'first_name' => 'B',
            'email' => 'b-login@example.com',
            'created_by_user_id' => $adminB->id,
            'updated_by_user_id' => $adminB->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);

        Sanctum::actingAs($adminA);
        $response = $this->getJson('/api/dashboard')->assertOk();
        $response->assertJsonPath('data.dashboard_profile', Role::CODE_COMPANY_ADMIN);
        $response->assertJsonPath('data.tenant_id', $tenantA->id);
        $this->assertSame(1, $response->json('data.kpis.total_contacts'));
        $this->assertArrayHasKey('recent_crm_activity', $response->json('data.widgets'));
    }

    public function test_partner_admin_login_dashboard_reuses_prm_metrics_with_enhancements(): void
    {
        [$tenant, $admin, , $partner, $reseller] = $this->seedPartnerWithReseller();
        $partnerAdmin = $this->makeUser($tenant->id, Role::CODE_PARTNER_ADMIN, 'partner-login@example.com', $partner->id);

        Contact::query()->create([
            'tenant_id' => $tenant->id,
            'channel_organization_id' => $reseller->id,
            'assigned_user_id' => $partnerAdmin->id,
            'first_name' => 'Assigned',
            'email' => 'assigned@example.com',
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);

        OrganizationInvitation::query()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $partner->id,
            'email' => 'invite@example.com',
            'token_hash' => hash('sha256', 'token'),
            'role_code' => Role::CODE_PARTNER_SALES_CONSULTANT,
            'invited_by_user_id' => $admin->id,
            'status' => OrganizationInvitation::STATUS_PENDING,
            'expires_at' => now()->addDays(7),
        ]);

        Task::query()->create([
            'tenant_id' => $tenant->id,
            'scope_organization_id' => $partner->id,
            'title' => 'Follow up',
            'priority' => Task::PRIORITY_HIGH,
            'status' => Task::STATUS_PENDING,
            'due_at' => now()->subDay(),
            'assignee_user_id' => $partnerAdmin->id,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'related_type' => Task::RELATED_CONTACT,
            'related_id' => 1,
        ]);

        Sanctum::actingAs($partnerAdmin);
        $response = $this->getJson('/api/dashboard')->assertOk();

        $response->assertJsonPath('data.dashboard_profile', Role::CODE_PARTNER_ADMIN);
        $this->assertArrayHasKey('contacts', $response->json('data.kpis'));
        $this->assertArrayNotHasKey('leads', $response->json('data.kpis'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.kpis.assigned_contacts'));
        $this->assertArrayHasKey('task_summary', $response->json('data.kpis'));
        $this->assertArrayHasKey('invitation_status', $response->json('data.kpis'));
    }

    public function test_reseller_admin_login_dashboard_returns_enhancements(): void
    {
        [, , , , $reseller] = $this->seedPartnerWithReseller();
        $resellerAdmin = $this->makeUser($reseller->tenant_id, Role::CODE_RESELLER_ADMIN, 'reseller-login@example.com', $reseller->id);

        Sanctum::actingAs($resellerAdmin);
        $response = $this->getJson('/api/dashboard')->assertOk();

        $response->assertJsonPath('data.dashboard_profile', Role::CODE_RESELLER_ADMIN);
        $this->assertArrayHasKey('assigned_contacts', $response->json('data.kpis'));
        $this->assertArrayHasKey('task_summary', $response->json('data.kpis'));
        $this->assertArrayHasKey('recent_notifications', $response->json('data.kpis'));
    }

    public function test_finance_admin_and_consultant_receive_403(): void
    {
        [$tenant, $admin] = $this->seedTenantCompanyAdmin();
        $finance = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Finance',
            'email' => 'finance-login@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_FINANCE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($finance);
        $this->getJson('/api/dashboard')->assertForbidden();

        [, , , , $reseller] = $this->seedPartnerWithReseller();
        $consultant = $this->makeUser($reseller->tenant_id, Role::CODE_RESELLER_SALES_CONSULTANT, 'consultant-login@example.com', $reseller->id);
        Sanctum::actingAs($consultant);
        $this->getJson('/api/dashboard')->assertForbidden();
    }

    public function test_invalid_date_range_returns_422(): void
    {
        $tenant = $this->makeActiveTenant();
        $admin = $this->makeCompanyAdmin($tenant);
        Sanctum::actingAs($admin);

        $this->getJson('/api/dashboard?from=2026-05-10&to=2026-05-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);
    }

    public function test_login_dashboard_cache_is_stable(): void
    {
        Cache::flush();
        $tenant = $this->makeActiveTenant();
        $admin = $this->makeCompanyAdmin($tenant);
        Contact::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Cache',
            'email' => 'cache@example.com',
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);

        Sanctum::actingAs($admin);
        $first = $this->getJson('/api/dashboard')->assertOk()->json('data.kpis.total_contacts');
        $second = $this->getJson('/api/dashboard')->assertOk()->json('data.kpis.total_contacts');
        $this->assertSame($first, $second);
    }

    public function test_company_admin_audit_widget_when_permitted(): void
    {
        $tenant = $this->makeActiveTenant();
        $admin = $this->makeCompanyAdmin($tenant);

        AuditLog::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'module' => 'contacts',
            'action' => 'created',
            'entity_type' => 'contact',
            'entity_id' => 1,
            'before' => null,
            'after' => ['id' => 1],
        ]);

        Sanctum::actingAs($admin);
        $widgets = $this->getJson('/api/dashboard')->assertOk()->json('data.widgets');
        $this->assertArrayHasKey('recent_audit_activity', $widgets);
        $this->assertNotEmpty($widgets['recent_audit_activity']);
    }

    public function test_prm_partner_dashboard_contract_unchanged(): void
    {
        [, , , $partner] = $this->seedPartnerWithReseller();
        $partnerAdmin = $this->makeUser($partner->tenant_id, Role::CODE_PARTNER_ADMIN, 'prm-unchanged@example.com', $partner->id);

        Sanctum::actingAs($partnerAdmin);
        $response = $this->getJson('/api/prm/partner/dashboard')->assertOk();
        $this->assertArrayHasKey('summary', $response->json('data'));
        $this->assertArrayHasKey('leads', $response->json('data.summary.counts'));
    }

    public function test_partner_notification_enhancement_when_permission_granted(): void
    {
        [, , , $partner] = $this->seedPartnerWithReseller();
        $partnerAdmin = $this->makeUser($partner->tenant_id, Role::CODE_PARTNER_ADMIN, 'notify-partner@example.com', $partner->id);

        InAppNotification::query()->create([
            'tenant_id' => $partner->tenant_id,
            'organization_id' => $partner->id,
            'recipient_user_id' => $partnerAdmin->id,
            'notification_type' => 'task.assigned',
            'category' => 'tasks',
            'title' => 'Task assigned',
            'message' => 'You have a new task',
            'priority' => InAppNotification::PRIORITY_NORMAL,
            'is_read' => false,
        ]);

        Sanctum::actingAs($partnerAdmin);
        $items = $this->getJson('/api/dashboard')->assertOk()->json('data.kpis.recent_notifications');
        $this->assertCount(1, $items);
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Organization, 3: Organization, 4: Organization}
     */
    private function seedPartnerWithReseller(): array
    {
        $tenant = $this->makeActiveTenant();
        $admin = $this->makeCompanyAdmin($tenant);
        $orgs = $this->seedCompanyPartnerResellerHierarchy($tenant, $admin);

        return [$tenant, $admin, $orgs['company'], $orgs['partner'], $orgs['reseller']];
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function seedTenantCompanyAdmin(): array
    {
        $tenant = $this->makeActiveTenant();
        $admin = $this->makeCompanyAdmin($tenant);

        return [$tenant, $admin];
    }

    private function makeUser(int $tenantId, string $role, string $email, int $orgId): User
    {
        $user = User::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'U',
            'email' => $email,
            'password' => 'secret123',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        UserOrganizationAssignment::query()->create([
            'user_id' => $user->id,
            'organization_id' => $orgId,
        ]);

        return $user;
    }
}
