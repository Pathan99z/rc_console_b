<?php

namespace Tests\Feature\Prm;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserOrganizationAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ensures company-owned CRM rows do not leak to resellers via deal partner_organization_id alone.
 * Assignment (assigned_user_id) and channel-stamped rows remain visible.
 */
class ChannelCrmIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseller_does_not_see_company_contact_linked_only_by_deal_partner_org(): void
    {
        Cache::flush();
        [$tenant, $companyAdmin, $company, $partner, $reseller] = $this->seedHierarchy();
        $resellerAdmin = $this->makeChannelUser($tenant, Role::CODE_RESELLER_ADMIN, 'iso-reseller@example.com', $reseller->id);

        $companyContact = Contact::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Company',
            'last_name' => 'Only',
            'email' => 'company-only-'.uniqid('', true).'@example.com',
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'assigned_user_id' => $companyAdmin->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);

        $pipeline = $this->seedPipeline($tenant, $companyAdmin);
        $stageId = (int) PipelineStage::query()->where('pipeline_id', $pipeline->id)->value('id');

        Deal::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'UAT Deal',
            'contact_id' => $companyContact->id,
            'owner_user_id' => $companyAdmin->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $stageId,
            'partner_organization_id' => $reseller->id,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'status' => Deal::STATUS_OPEN,
        ]);

        Sanctum::actingAs($resellerAdmin);
        $emails = collect($this->getJson('/api/contacts')->assertOk()->json('data.items'))->pluck('email');
        $this->assertFalse($emails->contains($companyContact->email));
    }

    public function test_reseller_sees_contact_assigned_by_company_admin(): void
    {
        Cache::flush();
        [$tenant, $companyAdmin, , , $reseller] = $this->seedHierarchy();
        $resellerAdmin = $this->makeChannelUser($tenant, Role::CODE_RESELLER_ADMIN, 'iso-assigned@example.com', $reseller->id);

        $assigned = Contact::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Assigned',
            'last_name' => 'ToReseller',
            'email' => 'assigned-reseller-'.uniqid('', true).'@example.com',
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'assigned_user_id' => $resellerAdmin->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);

        Sanctum::actingAs($resellerAdmin);
        $emails = collect($this->getJson('/api/contacts')->assertOk()->json('data.items'))->pluck('email');
        $this->assertTrue($emails->contains($assigned->email));
    }

    public function test_reseller_sees_own_channel_stamped_contact(): void
    {
        Cache::flush();
        [$tenant, $companyAdmin, , , $reseller] = $this->seedHierarchy();
        $resellerAdmin = $this->makeChannelUser($tenant, Role::CODE_RESELLER_ADMIN, 'iso-channel@example.com', $reseller->id);

        Sanctum::actingAs($resellerAdmin);
        $this->postJson('/api/contacts', [
            'first_name' => 'Reseller',
            'last_name' => 'Own',
            'email' => 'reseller-own-'.uniqid('', true).'@example.com',
        ])->assertCreated();

        $emails = collect($this->getJson('/api/contacts')->assertOk()->json('data.items'))->pluck('email');
        $this->assertGreaterThanOrEqual(1, $emails->count());
    }

    public function test_company_admin_still_sees_all_tenant_contacts(): void
    {
        Cache::flush();
        [$tenant, $companyAdmin] = $this->seedHierarchy();

        Contact::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'A',
            'email' => 'ca-a@example.com',
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);
        Contact::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'B',
            'email' => 'ca-b@example.com',
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
        ]);

        Sanctum::actingAs($companyAdmin);
        $this->assertGreaterThanOrEqual(2, count($this->getJson('/api/contacts')->assertOk()->json('data.items')));
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Organization, 3: Organization, 4: Organization}
     */
    private function seedHierarchy(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Iso Tenant', 'status' => Tenant::STATUS_ACTIVE]);
        $companyAdmin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Company Admin',
            'email' => 'ca-iso-'.uniqid('', true).'@example.com',
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

        return [$tenant, $companyAdmin, $company, $partner, $reseller];
    }

    private function makeChannelUser(Tenant $tenant, string $role, string $email, int $organizationId): User
    {
        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Channel',
            'email' => $email,
            'password' => 'secret123',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        UserOrganizationAssignment::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
        ]);

        return $user;
    }

    private function seedPipeline(Tenant $tenant, User $actor): Pipeline
    {
        $pipeline = Pipeline::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $actor->id,
            'name' => 'Default',
            'status' => Pipeline::STATUS_ACTIVE,
        ]);
        PipelineStage::query()->create([
            'tenant_id' => $tenant->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Stage',
            'stage_order' => 1,
            'status' => PipelineStage::STATUS_ACTIVE,
        ]);

        return $pipeline;
    }
}
