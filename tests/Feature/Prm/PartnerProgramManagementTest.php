<?php

namespace Tests\Feature\Prm;

use App\Models\Organization;
use App\Models\PartnerProgram;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserOrganizationAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartnerProgramManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_create_and_update_program(): void
    {
        [$tenant, $companyAdmin, $partnerOrg] = $this->tenantWithPartner();
        Sanctum::actingAs($companyAdmin);

        $create = $this->postJson('/api/prm/programs', [
            'code' => 'msp_premium',
            'name' => 'MSP Premium',
            'description' => 'Enterprise MSP tier',
            'tier_level' => 5,
            'default_commission_percent' => 12.5,
            'rules' => ['benefits' => ['priority_support']],
            'metadata' => ['segment' => 'msp'],
            'is_template' => false,
        ])->assertCreated();

        $create->assertJsonPath('data.program.code', 'msp_premium')
            ->assertJsonPath('data.program.status', 'active');

        $programId = (int) $create->json('data.program.id');

        $this->putJson('/api/prm/programs/'.$programId, [
            'name' => 'MSP Premium Plus',
            'default_commission_percent' => 13,
        ])->assertOk()
            ->assertJsonPath('data.program.name', 'MSP Premium Plus');
    }

    public function test_duplicate_code_rejected_on_create(): void
    {
        [$tenant, $companyAdmin, $partnerOrg] = $this->tenantWithPartner();
        PartnerProgram::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'existing',
            'name' => 'Existing',
            'tier_level' => 1,
            'default_commission_percent' => 5,
            'status' => PartnerProgram::STATUS_ACTIVE,
            'rules' => [],
            'is_template' => false,
        ]);

        Sanctum::actingAs($companyAdmin);

        $this->postJson('/api/prm/programs', [
            'code' => 'existing',
            'name' => 'Duplicate',
            'tier_level' => 2,
            'default_commission_percent' => 6,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_enroll_rejects_inactive_program(): void
    {
        [$tenant, $companyAdmin, $partnerOrg] = $this->tenantWithPartner();
        $inactive = PartnerProgram::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'sunset',
            'name' => 'Sunset',
            'tier_level' => 1,
            'default_commission_percent' => 5,
            'status' => PartnerProgram::STATUS_INACTIVE,
            'rules' => [],
            'is_template' => false,
        ]);

        Sanctum::actingAs($companyAdmin);

        $this->postJson('/api/prm/programs/enroll', [
            'organization_id' => $partnerOrg->id,
            'partner_program_id' => $inactive->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['partner_program_id']);
    }

    public function test_status_toggle_active_inactive(): void
    {
        [$tenant, $companyAdmin, $partnerOrg] = $this->tenantWithPartner();
        $program = PartnerProgram::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'toggle_me',
            'name' => 'Toggle',
            'tier_level' => 1,
            'default_commission_percent' => 5,
            'status' => PartnerProgram::STATUS_ACTIVE,
            'rules' => [],
            'is_template' => false,
        ]);

        Sanctum::actingAs($companyAdmin);

        $this->patchJson('/api/prm/programs/'.$program->id.'/status', [
            'status' => PartnerProgram::STATUS_INACTIVE,
        ])->assertOk()
            ->assertJsonPath('data.program.status', PartnerProgram::STATUS_INACTIVE);

        $this->patchJson('/api/prm/programs/'.$program->id.'/status', [
            'status' => PartnerProgram::STATUS_ACTIVE,
        ])->assertOk()
            ->assertJsonPath('data.program.status', PartnerProgram::STATUS_ACTIVE);
    }

    public function test_partner_admin_cannot_list_programs(): void
    {
        [$tenant, $companyAdmin, $partnerOrg] = $this->tenantWithPartner();
        $partnerUser = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Partner',
            'email' => 'partner-prm-'.uniqid('', true).'@example.com',
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

        $this->getJson('/api/prm/programs')->assertForbidden();
    }

    public function test_regular_user_cannot_manage_programs(): void
    {
        [$tenant, $companyAdmin, $partnerOrg] = $this->tenantWithPartner();
        $plainUser = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Plain',
            'email' => 'plain-prm-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_USER,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($plainUser);

        $this->getJson('/api/prm/programs')->assertForbidden();
    }

    public function test_global_admin_requires_tenant_id_for_program_show(): void
    {
        [$tenant, $companyAdmin, $partnerOrg] = $this->tenantWithPartner();
        $program = PartnerProgram::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'ga_show',
            'name' => 'GA Show',
            'tier_level' => 1,
            'default_commission_percent' => 5,
            'status' => PartnerProgram::STATUS_ACTIVE,
            'rules' => [],
            'is_template' => false,
        ]);

        $globalAdmin = User::query()->create([
            'tenant_id' => null,
            'name' => 'Global',
            'email' => 'ga-prm-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_GLOBAL_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($globalAdmin);

        $this->getJson('/api/prm/programs/'.$program->id)->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id']);

        $this->getJson('/api/prm/programs/'.$program->id.'?tenant_id='.$tenant->id)->assertOk()
            ->assertJsonPath('data.program.code', 'ga_show');
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Organization}
     */
    private function tenantWithPartner(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant PRM Mgmt', 'status' => Tenant::STATUS_ACTIVE]);
        $companyAdmin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'CA',
            'email' => 'ca-prm-mgmt-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $root = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Root',
            'display_name' => 'Root',
            'status' => Organization::STATUS_ACTIVE,
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $partnerOrg = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $root->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner',
            'display_name' => 'Partner',
            'status' => Organization::STATUS_ACTIVE,
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        return [$tenant, $companyAdmin, $partnerOrg];
    }
}
