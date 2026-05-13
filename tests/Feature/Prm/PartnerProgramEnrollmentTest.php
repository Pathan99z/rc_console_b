<?php

namespace Tests\Feature\Prm;

use App\Models\Organization;
use App\Models\PartnerProgram;
use App\Models\PartnerProgramEnrollment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserOrganizationAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartnerProgramEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_enroll_derives_tier_code_from_program_and_ignores_client_mismatch(): void
    {
        [$tenant, $companyAdmin, $partnerOrg, $program] = $this->tenantAdminPartnerAndProgram();

        Sanctum::actingAs($companyAdmin);

        $this->postJson('/api/prm/programs/enroll', [
            'organization_id' => $partnerOrg->id,
            'partner_program_id' => $program->id,
            'tier_code' => 'gold',
            'commission_percent' => 7.5,
        ])->assertCreated()
            ->assertJsonPath('data.enrollment.tier_code', 'silver')
            ->assertJsonPath('data.enrollment.program_name', 'Silver')
            ->assertJsonPath('data.enrollment.organization.display_name', 'Partner Co');

        $this->assertDatabaseHas('partner_program_enrollments', [
            'organization_id' => $partnerOrg->id,
            'partner_program_id' => $program->id,
            'tier_code' => 'silver',
            'status' => PartnerProgramEnrollment::STATUS_ACTIVE,
        ]);
    }

    public function test_enroll_without_tier_code_succeeds(): void
    {
        [$tenant, $companyAdmin, $partnerOrg, $program] = $this->tenantAdminPartnerAndProgram();

        Sanctum::actingAs($companyAdmin);

        $this->postJson('/api/prm/programs/enroll', [
            'organization_id' => $partnerOrg->id,
            'partner_program_id' => $program->id,
            'commission_percent' => 10,
        ])->assertCreated()
            ->assertJsonPath('data.enrollment.tier_code', 'silver');
    }

    public function test_enroll_rejects_non_partner_organization(): void
    {
        [$tenant, $companyAdmin, $partnerOrg, $program] = $this->tenantAdminPartnerAndProgram();
        $rootCompany = Organization::query()->where('tenant_id', $tenant->id)
            ->where('type', Organization::TYPE_COMPANY)
            ->firstOrFail();

        Sanctum::actingAs($companyAdmin);

        $this->postJson('/api/prm/programs/enroll', [
            'organization_id' => $rootCompany->id,
            'partner_program_id' => $program->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_id']);
    }

    public function test_partner_can_list_active_enrollments_for_primary_org_only(): void
    {
        [$tenant, $companyAdmin, $partnerOrg, $silver] = $this->tenantAdminPartnerAndProgram();
        $gold = PartnerProgram::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'gold',
            'name' => 'Gold',
            'tier_level' => 2,
            'default_commission_percent' => 10.0,
            'rules' => [],
            'is_template' => false,
        ]);

        PartnerProgramEnrollment::query()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $partnerOrg->id,
            'partner_program_id' => $silver->id,
            'tier_code' => 'silver',
            'commission_percent' => 5.0,
            'status' => PartnerProgramEnrollment::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
        ]);
        PartnerProgramEnrollment::query()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $partnerOrg->id,
            'partner_program_id' => $gold->id,
            'tier_code' => 'gold',
            'commission_percent' => 8.0,
            'status' => PartnerProgramEnrollment::STATUS_SUSPENDED,
            'created_by_user_id' => $companyAdmin->id,
        ]);

        $partnerUser = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Partner Admin',
            'email' => 'partner-enroll-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_PARTNER_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        UserOrganizationAssignment::query()->create([
            'user_id' => $partnerUser->id,
            'organization_id' => $partnerOrg->id,
        ]);

        Sanctum::actingAs($partnerUser);

        $response = $this->getJson('/api/prm/partner/program-enrollments')->assertOk();
        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame($silver->id, $items[0]['partner_program_id']);
        $this->assertSame(PartnerProgramEnrollment::STATUS_ACTIVE, $items[0]['status']);
        $this->assertSame('Silver', $items[0]['program_name']);
        $this->assertSame('Partner Co', $items[0]['organization']['display_name']);
    }

    public function test_global_admin_programs_list_requires_tenant_id_query(): void
    {
        [$tenant, $companyAdmin, $partnerOrg, $program] = $this->tenantAdminPartnerAndProgram();

        $globalAdmin = User::query()->create([
            'tenant_id' => null,
            'name' => 'Global',
            'email' => 'ga-enroll-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_GLOBAL_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($globalAdmin);

        $this->getJson('/api/prm/programs')->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id']);

        $this->getJson('/api/prm/programs?tenant_id='.$tenant->id)->assertOk()
            ->assertJsonPath('data.items.0.code', 'silver');
    }

    public function test_enroll_twice_same_program_is_idempotent_single_row(): void
    {
        [$tenant, $companyAdmin, $partnerOrg, $program] = $this->tenantAdminPartnerAndProgram();

        Sanctum::actingAs($companyAdmin);

        $this->postJson('/api/prm/programs/enroll', [
            'organization_id' => $partnerOrg->id,
            'partner_program_id' => $program->id,
            'commission_percent' => 5,
        ])->assertCreated();

        $this->postJson('/api/prm/programs/enroll', [
            'organization_id' => $partnerOrg->id,
            'partner_program_id' => $program->id,
            'commission_percent' => 6,
        ])->assertCreated();

        $this->assertSame(1, PartnerProgramEnrollment::query()
            ->where('organization_id', $partnerOrg->id)
            ->where('partner_program_id', $program->id)
            ->count());
        $this->assertDatabaseHas('partner_program_enrollments', [
            'organization_id' => $partnerOrg->id,
            'partner_program_id' => $program->id,
            'commission_percent' => 6,
        ]);
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Organization, 3: PartnerProgram}
     */
    private function tenantAdminPartnerAndProgram(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant PRM', 'status' => Tenant::STATUS_ACTIVE]);
        $companyAdmin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Company Admin',
            'email' => 'ca-enroll-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $root = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Root Legal',
            'display_name' => 'Root Co',
            'status' => Organization::STATUS_ACTIVE,
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $partnerOrg = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $root->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner Legal',
            'display_name' => 'Partner Co',
            'status' => Organization::STATUS_ACTIVE,
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $program = PartnerProgram::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'silver',
            'name' => 'Silver',
            'tier_level' => 1,
            'default_commission_percent' => 5.0,
            'rules' => [],
            'is_template' => false,
        ]);

        return [$tenant, $companyAdmin, $partnerOrg, $program];
    }
}
