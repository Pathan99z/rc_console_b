<?php

namespace App\Services\Prm;

use App\Mail\Prm\PartnerInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationAssignment;
use App\Repositories\AuditLogRepository;
use App\Repositories\OrganizationInvitationRepository;
use App\Repositories\OrganizationRepository;
use App\Repositories\UserRepository;
use App\Support\DomainConstants;
use App\Events\Notifications\PartnerInvitationAccepted;
use App\Events\Notifications\ResellerInvitationAccepted;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrganizationInvitationService
{
    public function __construct(
        private readonly OrganizationInvitationRepository $invitationRepository,
        private readonly OrganizationRepository $organizationRepository,
        private readonly UserRepository $userRepository,
        private readonly AuditLogRepository $auditLogRepository,
    ) {}

    public function listInvitations(User $actor, int $organizationId, int $perPage): LengthAwarePaginator
    {
        $this->assertCanManageInvitations($actor, $organizationId);
        $this->mustBePartnerOrResellerOrganization($organizationId);

        return $this->invitationRepository->paginateForOrganization($organizationId, $perPage);
    }

    /**
     * @return array{invitation: OrganizationInvitation, plain_token: string}
     */
    public function createInvitation(
        User $actor,
        int $organizationId,
        string $email,
        string $roleCode,
        ?int $expiresInDays,
        ?string $ipAddress,
        ?string $userAgent
    ): array {
        $this->assertCanManageInvitations($actor, $organizationId);
        $this->mustBePartnerOrResellerOrganization($organizationId);
        $organization = $this->mustOrganization($organizationId);
        $this->assertRoleMatchesOrganizationType($organization, $roleCode);
        $this->assertActorAllowedForOrganizationType($actor, $organization, $roleCode);

        $email = strtolower(trim($email));
        if ($this->userRepository->findByEmail($email)) {
            throw ValidationException::withMessages([
                'email' => ['A user with this email already exists.'],
            ]);
        }

        if ($this->invitationRepository->findPendingDuplicate($organizationId, $email)) {
            throw ValidationException::withMessages([
                'email' => ['A pending invitation already exists for this email.'],
            ]);
        }

        $plainToken = Str::random(48);
        $tokenHash = hash('sha256', $plainToken);
        $days = $expiresInDays ?? (int) config('prm.invite_expiry_days', 7);

        $invitation = $this->invitationRepository->create([
            'tenant_id' => $organization->tenant_id,
            'organization_id' => $organization->id,
            'email' => $email,
            'token_hash' => $tokenHash,
            'role_code' => $roleCode,
            'invited_by_user_id' => $actor->id,
            'status' => OrganizationInvitation::STATUS_PENDING,
            'expires_at' => now()->addDays(max(1, $days)),
            'last_sent_at' => now(),
            'send_count' => 1,
        ]);

        $this->sendMail($invitation, $plainToken, $roleCode);
        $this->auditPrm($actor, $organization->tenant_id, 'prm.invitation.created', $invitation->id, null, [
            'organization_id' => $organization->id,
            'email' => $email,
            'role_code' => $roleCode,
        ], $ipAddress, $userAgent);

        return ['invitation' => $invitation, 'plain_token' => $plainToken];
    }

    public function resendInvitation(
        User $actor,
        int $organizationId,
        int $invitationId,
        ?string $ipAddress,
        ?string $userAgent
    ): void {
        $this->assertCanManageInvitations($actor, $organizationId);
        $invitation = $this->invitationRepository->findByIdForOrganization($organizationId, $invitationId);
        if (! $invitation || $invitation->status !== OrganizationInvitation::STATUS_PENDING) {
            throw new ModelNotFoundException(DomainConstants::MSG_PRM_INVITATION_NOT_FOUND);
        }

        if ($invitation->expires_at && $invitation->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'invitation' => ['Invitation has expired. Create a new invitation.'],
            ]);
        }

        $plainToken = Str::random(48);
        $updated = $this->invitationRepository->update($invitation, [
            'token_hash' => hash('sha256', $plainToken),
            'last_sent_at' => now(),
            'send_count' => $invitation->send_count + 1,
        ]);

        $this->sendMail($updated->load('organization'), $plainToken, $updated->role_code);
        $this->auditPrm($actor, $updated->tenant_id, 'prm.invitation.resent', $updated->id, null, [
            'send_count' => $updated->send_count,
        ], $ipAddress, $userAgent);
    }

    public function revokeInvitation(
        User $actor,
        int $organizationId,
        int $invitationId,
        ?string $ipAddress,
        ?string $userAgent
    ): OrganizationInvitation {
        $this->assertCanManageInvitations($actor, $organizationId);
        $invitation = $this->invitationRepository->findByIdForOrganization($organizationId, $invitationId);
        if (! $invitation || $invitation->status !== OrganizationInvitation::STATUS_PENDING) {
            throw new ModelNotFoundException(DomainConstants::MSG_PRM_INVITATION_NOT_FOUND);
        }

        $before = $invitation->toArray();
        $updated = $this->invitationRepository->update($invitation, [
            'status' => OrganizationInvitation::STATUS_REVOKED,
        ]);
        $this->auditPrm($actor, $invitation->tenant_id, 'prm.invitation.revoked', $invitation->id, $before, $updated->toArray(), $ipAddress, $userAgent);

        return $updated;
    }

    /**
     * @return array{organization_display_name: string, email_masked: string, expires_at: string|null, role_code: string}
     */
    public function previewByPlainToken(string $plainToken): array
    {
        $invitation = $this->resolvePendingInvitation($plainToken);

        return [
            'organization_display_name' => (string) $invitation->organization?->display_name,
            'email_masked' => $this->maskEmail($invitation->email),
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'role_code' => $invitation->role_code,
        ];
    }

    /**
     * @return array{user: User, token: string|null, requires_email_verification: bool}
     */
    public function acceptInvitation(
        string $plainToken,
        string $name,
        string $password,
        bool $termsAccepted,
        ?string $ipAddress,
        ?string $userAgent
    ): array {
        if (! $termsAccepted) {
            throw ValidationException::withMessages([
                'terms_accepted' => ['You must accept the terms to continue.'],
            ]);
        }

        return DB::transaction(function () use ($plainToken, $name, $password, $ipAddress, $userAgent): array {
            $invitation = $this->resolvePendingInvitation($plainToken, lock: true);
            $organization = $invitation->organization;
            if (! $organization) {
                throw ValidationException::withMessages(['token' => ['Invalid invitation.']]);
            }

            if ($this->userRepository->findByEmail($invitation->email)) {
                throw ValidationException::withMessages([
                    'email' => ['This email is already registered.'],
                ]);
            }

            $roleId = Role::query()->where('code', $invitation->role_code)->value('id');
            if (! $roleId) {
                throw ValidationException::withMessages(['token' => ['Invalid invitation role.']]);
            }

            $autoVerifyInvitedUsers = (bool) config('prm.auto_verify_invited_users', false);

            $user = $this->userRepository->create([
                'tenant_id' => $invitation->tenant_id,
                'role' => $invitation->role_code,
                'role_id' => $roleId,
                'status' => User::STATUS_ACTIVE,
                'name' => trim($name),
                'email' => $invitation->email,
                'password' => $password,
                'email_verified_at' => $autoVerifyInvitedUsers ? now() : null,
            ]);

            UserOrganizationAssignment::query()->updateOrCreate(
                ['user_id' => $user->id],
                ['organization_id' => $invitation->organization_id]
            );

            $this->invitationRepository->update($invitation, [
                'status' => OrganizationInvitation::STATUS_ACCEPTED,
                'accepted_at' => now(),
                'terms_accepted_at' => now(),
                'accepted_user_id' => $user->id,
            ]);

            $this->auditPrm($user, $invitation->tenant_id, 'prm.invitation.accepted', $invitation->id, null, [
                'user_id' => $user->id,
            ], $ipAddress, $userAgent);

            if ($organization->type === Organization::TYPE_PARTNER) {
                event(new PartnerInvitationAccepted($organization->id, $user->id));
            } elseif ($organization->type === Organization::TYPE_RESELLER) {
                event(new ResellerInvitationAccepted($organization->id, $user->id));
            }

            if (! $autoVerifyInvitedUsers) {
                $user->sendEmailVerificationNotification();
            }

            $token = $autoVerifyInvitedUsers ? $user->createToken('web')->plainTextToken : null;

            return [
                'user' => $user->fresh(['tenant', 'roleModel', 'organizationAssignment.organization']),
                'token' => $token,
                'requires_email_verification' => ! $autoVerifyInvitedUsers,
            ];
        });
    }

    private function resolvePendingInvitation(string $plainToken, bool $lock = false): OrganizationInvitation
    {
        $hash = hash('sha256', $plainToken);
        $query = OrganizationInvitation::query()->where('token_hash', $hash);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var OrganizationInvitation|null $invitation */
        $invitation = $query->with('organization')->first();
        if (! $invitation || $invitation->status !== OrganizationInvitation::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'token' => ['Invalid or expired invitation.'],
            ]);
        }

        if ($invitation->expires_at && $invitation->expires_at->isPast()) {
            $this->invitationRepository->update($invitation, ['status' => OrganizationInvitation::STATUS_EXPIRED]);
            throw ValidationException::withMessages([
                'token' => ['This invitation has expired.'],
            ]);
        }

        return $invitation;
    }

    private function sendMail(OrganizationInvitation $invitation, string $plainToken, string $roleCode): void
    {
        $base = rtrim((string) config('prm.invite_accept_url'), '?');
        $acceptUrl = $base.(str_contains($base, '?') ? '&' : '?').'token='.urlencode($plainToken);
        $label = $roleCode === Role::CODE_RESELLER_ADMIN ? 'Reseller administrator' : 'Partner administrator';

        Mail::to($invitation->email)->send(new PartnerInvitationMail(
            (string) $invitation->organization?->display_name,
            $acceptUrl,
            $label,
        ));
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $len = strlen($local);
        if ($len <= 2) {
            return str_repeat('*', max(0, $len - 1)).substr($local, -1).'@'.$domain;
        }

        return substr($local, 0, 2).str_repeat('*', min(6, $len - 2)).substr($local, -1).'@'.$domain;
    }

    private function mustOrganization(int $organizationId): Organization
    {
        $org = $this->organizationRepository->findById($organizationId);
        if (! $org) {
            throw new ModelNotFoundException(DomainConstants::MSG_ORGANIZATION_NOT_FOUND);
        }

        return $org;
    }

    private function mustBePartnerOrResellerOrganization(int $organizationId): void
    {
        $org = $this->mustOrganization($organizationId);
        if (! in_array($org->type, [Organization::TYPE_PARTNER, Organization::TYPE_RESELLER], true)) {
            throw ValidationException::withMessages([
                'organization_id' => ['Invitations are only supported for partner or reseller organizations.'],
            ]);
        }
    }

    private function assertRoleMatchesOrganizationType(Organization $organization, string $roleCode): void
    {
        if ($organization->type === Organization::TYPE_PARTNER && $roleCode !== Role::CODE_PARTNER_ADMIN) {
            throw ValidationException::withMessages([
                'role_code' => ['Partner organizations can only invite partner_admin for initial onboarding.'],
            ]);
        }
        if ($organization->type === Organization::TYPE_RESELLER
            && ! in_array($roleCode, [
                Role::CODE_RESELLER_ADMIN,
                Role::CODE_RESELLER_SALES_MANAGER,
                Role::CODE_RESELLER_SALES_CONSULTANT,
            ], true)) {
            throw ValidationException::withMessages([
                'role_code' => ['Invalid role for reseller organization.'],
            ]);
        }
    }

    private function assertActorAllowedForOrganizationType(User $actor, Organization $organization, string $roleCode): void
    {
        if ($actor->isGlobalAdmin()) {
            return;
        }

        if ($actor->isCompanyAdmin()) {
            if ((int) $organization->tenant_id !== (int) $actor->tenant_id) {
                throw ValidationException::withMessages([
                    'organization_id' => [DomainConstants::MSG_UNAUTHORIZED_SCOPE],
                ]);
            }

            return;
        }

        if ($actor->isPartnerAdmin() && $organization->type === Organization::TYPE_RESELLER) {
            $tree = $this->organizationRepository->channelTreeOrganizationIds((int) ($actor->primaryOrganizationId() ?? 0));
            if (in_array($organization->id, $tree, true)) {
                return;
            }
        }

        if ($actor->currentRoleCode() === Role::CODE_RESELLER_ADMIN
            && $organization->type === Organization::TYPE_RESELLER
            && (int) $organization->id === (int) ($actor->primaryOrganizationId() ?? 0)
            && $roleCode !== Role::CODE_RESELLER_ADMIN) {
            return;
        }

        throw ValidationException::withMessages([
            'organization' => ['You are not allowed to manage invitations for this organization.'],
        ]);
    }

    private function assertCanManageInvitations(User $actor, int $organizationId): void
    {
        $org = $this->mustOrganization($organizationId);
        if ($actor->isGlobalAdmin()) {
            return;
        }
        if ((int) $org->tenant_id !== (int) $actor->tenant_id) {
            throw ValidationException::withMessages([
                'organization_id' => [DomainConstants::MSG_UNAUTHORIZED_SCOPE],
            ]);
        }

        if ($actor->isCompanyAdmin()) {
            return;
        }

        if ($actor->isPartnerAdmin()) {
            if ($org->type !== Organization::TYPE_RESELLER) {
                throw ValidationException::withMessages([
                    'organization' => ['Partner administrators can only manage invitations for reseller organizations.'],
                ]);
            }
            $tree = $this->organizationRepository->channelTreeOrganizationIds((int) ($actor->primaryOrganizationId() ?? 0));
            if (! in_array($org->id, $tree, true)) {
                throw ValidationException::withMessages([
                    'organization_id' => [DomainConstants::MSG_UNAUTHORIZED_SCOPE],
                ]);
            }

            return;
        }

        if ($actor->currentRoleCode() === Role::CODE_RESELLER_ADMIN
            && $org->type === Organization::TYPE_RESELLER
            && (int) $org->id === (int) ($actor->primaryOrganizationId() ?? 0)) {
            return;
        }

        throw ValidationException::withMessages([
            'organization' => ['You are not allowed to manage organization invitations.'],
        ]);
    }

    private function auditPrm(
        ?User $actor,
        int $tenantId,
        string $action,
        int $entityId,
        ?array $before,
        ?array $after,
        ?string $ipAddress,
        ?string $userAgent
    ): void {
        $this->auditLogRepository->create([
            'tenant_id' => $tenantId,
            'user_id' => $actor?->id,
            'module' => 'prm',
            'action' => $action,
            'entity_type' => 'organization_invitation',
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }
}
