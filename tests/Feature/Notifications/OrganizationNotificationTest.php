<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Notifications\InAppNotificationTemplateKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Concerns\BuildsTenantUsersForNotifications;
use Tests\TestCase;

final class OrganizationNotificationTest extends TestCase
{
    use BuildsTenantUsersForNotifications;
    use RefreshDatabase;

    private const EP = '/api/organizations';

    /** @return array{tenant:\App\Models\Tenant,admin:User,company:Organization} */
    private function bootstrapActiveCompany(): array
    {
        $tenant = $this->makeActiveTenant();
        $admin = $this->makeCompanyAdmin($tenant);
        $company = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Root Co',
            'display_name' => 'Root Co',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        return ['tenant' => $tenant, 'admin' => $admin, 'company' => $company];
    }

    private function approvePartnerSkeleton(\App\Models\Tenant $tenant, User $admin, Organization $company): int
    {
        Sanctum::actingAs($admin);

        $partnerId = (int) $this->postJson(self::EP, [
            'type' => Organization::TYPE_PARTNER,
            'parent_organization_id' => $company->id,
            'legal_name' => 'Parenter Legal '.uniqid(),
            'display_name' => 'Parenter',
            'email' => 'par-'.uniqid('', true).'@example.com',
            'onboarding_status' => Organization::ONBOARDING_PENDING_REVIEW,
        ])->assertCreated()->json('data.organization.id');

        $this->postJson(self::EP."/{$partnerId}/approve", [])->assertOk();

        return $partnerId;
    }

    public function test_partner_pending_review_creation_emits_partners_submitted(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'company' => $company] = $this->bootstrapActiveCompany();
        Sanctum::actingAs($admin);

        $this->postJson(self::EP, [
            'type' => Organization::TYPE_PARTNER,
            'parent_organization_id' => $company->id,
            'legal_name' => 'P Legal',
            'display_name' => 'Partner Pending',
            'email' => 'pp-'.uniqid('', true).'@example.com',
            'onboarding_status' => Organization::ONBOARDING_PENDING_REVIEW,
        ])->assertCreated();

        $this->assertGreaterThanOrEqual(
            1,
            \App\Models\InAppNotification::query()
                ->where('tenant_id', $tenant->id)
                ->where('notification_type', InAppNotificationTemplateKeys::PARTNERS_SUBMITTED)
                ->count()
        );
    }

    public function test_partner_pending_review_then_approve_reject_sequences_emit_partner_catalog_keys(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'company' => $company] = $this->bootstrapActiveCompany();
        Sanctum::actingAs($admin);

        $approveId = (int) $this->postJson(self::EP, [
            'type' => Organization::TYPE_PARTNER,
            'parent_organization_id' => $company->id,
            'legal_name' => 'ApproveMe',
            'display_name' => 'ApproveMe',
            'email' => 'pam-'.uniqid('', true).'@example.com',
            'onboarding_status' => Organization::ONBOARDING_PENDING_REVIEW,
        ])->assertCreated()->json('data.organization.id');

        $this->postJson(self::EP."/{$approveId}/approve", [])->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'notification_type' => InAppNotificationTemplateKeys::PARTNERS_APPROVED,
        ]);

        $rejectId = (int) $this->postJson(self::EP, [
            'type' => Organization::TYPE_PARTNER,
            'parent_organization_id' => $company->id,
            'legal_name' => 'RejectMe',
            'display_name' => 'RejectMe',
            'email' => 'prm-'.uniqid('', true).'@example.com',
            'onboarding_status' => Organization::ONBOARDING_PENDING_REVIEW,
        ])->assertCreated()->json('data.organization.id');

        $this->postJson(self::EP."/{$rejectId}/reject", ['reason' => 'coverage'])->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'notification_type' => InAppNotificationTemplateKeys::PARTNERS_REJECTED,
        ]);
    }

    public function test_partner_suspend_after_approval_writes_partners_suspended(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'company' => $company] = $this->bootstrapActiveCompany();
        $partnerId = $this->approvePartnerSkeleton($tenant, $admin, $company);

        Sanctum::actingAs($admin);
        $this->postJson(self::EP."/{$partnerId}/suspend", [])->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'notification_type' => InAppNotificationTemplateKeys::PARTNERS_SUSPENDED,
        ]);
    }

    public function test_reseller_submission_as_partner_admin_writes_resellers_submitted(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'company' => $company] = $this->bootstrapActiveCompany();
        $partnerId = $this->approvePartnerSkeleton($tenant, $admin, $company);

        $partnerAdmin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Partner Admin Org',
            'email' => 'pa-n2-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_PARTNER_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $this->assignUserOrganization($partnerAdmin, $partnerId);

        Sanctum::actingAs($partnerAdmin);

        $this->postJson(self::EP, [
            'type' => Organization::TYPE_RESELLER,
            'legal_name' => 'Child reseller Legal',
            'display_name' => 'Child reseller',
            'email' => 'cv-'.uniqid('', true).'@example.com',
            'onboarding_status' => Organization::ONBOARDING_PENDING_REVIEW,
        ])->assertCreated();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'notification_type' => InAppNotificationTemplateKeys::RESELLERS_SUBMITTED,
        ]);
    }

    public function test_reseller_approve_reject_suspend_writes_reseller_notifications(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'company' => $company] = $this->bootstrapActiveCompany();
        $partnerId = $this->approvePartnerSkeleton($tenant, $admin, $company);

        Sanctum::actingAs($admin);

        $rid = (int) $this->postJson(self::EP, [
            'type' => Organization::TYPE_RESELLER,
            'parent_organization_id' => $partnerId,
            'legal_name' => 'RSub',
            'display_name' => 'RSub',
            'email' => 'rsub-'.uniqid('', true).'@example.com',
            'onboarding_status' => Organization::ONBOARDING_PENDING_REVIEW,
        ])->assertCreated()->json('data.organization.id');

        $this->postJson(self::EP."/{$rid}/approve", [])->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'notification_type' => InAppNotificationTemplateKeys::RESELLERS_APPROVED,
        ]);

        $rj = (int) $this->postJson(self::EP, [
            'type' => Organization::TYPE_RESELLER,
            'parent_organization_id' => $partnerId,
            'legal_name' => 'RRej',
            'display_name' => 'RRej',
            'email' => 'rrj-'.uniqid('', true).'@example.com',
            'onboarding_status' => Organization::ONBOARDING_PENDING_REVIEW,
        ])->assertCreated()->json('data.organization.id');

        $this->postJson(self::EP."/{$rj}/reject", [])->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'notification_type' => InAppNotificationTemplateKeys::RESELLERS_REJECTED,
        ]);

        $rs = (int) $this->postJson(self::EP, [
            'type' => Organization::TYPE_RESELLER,
            'parent_organization_id' => $partnerId,
            'legal_name' => 'RSus',
            'display_name' => 'RSus',
            'email' => 'rsus-'.uniqid('', true).'@example.com',
            'onboarding_status' => Organization::ONBOARDING_PENDING_REVIEW,
        ])->assertCreated()->json('data.organization.id');

        $this->postJson(self::EP."/{$rs}/approve", [])->assertOk();
        $this->postJson(self::EP."/{$rs}/suspend", [])->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'notification_type' => InAppNotificationTemplateKeys::RESELLERS_SUSPENDED,
        ]);
    }

    public function test_partner_invitation_accepted_dispatches_partner_invitation_accepted_catalog_key(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'company' => $company] = $this->bootstrapActiveCompany();
        Sanctum::actingAs($admin);

        $partnerId = (int) $this->postJson(self::EP, [
            'type' => Organization::TYPE_PARTNER,
            'parent_organization_id' => $company->id,
            'legal_name' => 'InvPartner',
            'display_name' => 'InvPartner',
            'email' => 'inp-'.uniqid('', true).'@example.com',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
        ])->assertCreated()->json('data.organization.id');

        $acceptUser = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Invitee Partner',
            'email' => 'invp-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_PARTNER_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        event(new \App\Events\Notifications\PartnerInvitationAccepted($partnerId, $acceptUser->id));

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $acceptUser->id,
            'notification_type' => InAppNotificationTemplateKeys::PARTNERS_INVITATION_ACCEPTED,
        ]);
    }

    public function test_reseller_invitation_accepted_dispatches_reseller_catalog_key(): void
    {
        ['tenant' => $tenant, 'admin' => $admin, 'company' => $company] = $this->bootstrapActiveCompany();
        $partnerId = $this->approvePartnerSkeleton($tenant, $admin, $company);

        Sanctum::actingAs($admin);

        $rid = (int) $this->postJson(self::EP, [
            'type' => Organization::TYPE_RESELLER,
            'parent_organization_id' => $partnerId,
            'legal_name' => 'RInv Legal',
            'display_name' => 'R Inv',
            'email' => 'rinv-'.uniqid('', true).'@example.com',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
        ])->assertCreated()->json('data.organization.id');

        $acceptUser = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Res Invitee',
            'email' => 'rsvi-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_RESELLER_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $this->assignUserOrganization($acceptUser, $rid);

        event(new \App\Events\Notifications\ResellerInvitationAccepted($rid, $acceptUser->id));

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $acceptUser->id,
            'notification_type' => InAppNotificationTemplateKeys::RESELLERS_INVITATION_ACCEPTED,
        ]);
    }
}
