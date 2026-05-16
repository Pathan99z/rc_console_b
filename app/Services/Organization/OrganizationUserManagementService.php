<?php

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\OrganizationRepository;
use App\Repositories\UserRepository;
use App\Services\Prm\OrganizationInvitationService;
use App\Support\DomainConstants;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class OrganizationUserManagementService
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly UserRepository $userRepository,
        private readonly OrganizationInvitationService $invitationService,
        private readonly AuditLogRepository $auditLogRepository,
    ) {}

    public function listUsers(User $actor, int $organizationId, int $perPage): LengthAwarePaginator
    {
        $organization = $this->authorizedOrganization($actor, $organizationId);

        return User::query()
            ->with(['roleModel', 'organizationAssignment'])
            ->where('tenant_id', $organization->tenant_id)
            ->whereHas('organizationAssignment', fn ($q) => $q->where('organization_id', $organization->id))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @return array{invitation: \App\Models\OrganizationInvitation, plain_token: string}
     */
    public function inviteUser(
        User $actor,
        int $organizationId,
        string $email,
        string $roleCode,
        ?int $expiresInDays,
        ?string $ipAddress,
        ?string $userAgent
    ): array {
        $organization = $this->authorizedOrganization($actor, $organizationId);
        $this->assertInvitableRole($organization, $roleCode);
        $this->assertActorCanInviteRole($actor, $organization, $roleCode);

        return $this->invitationService->createInvitation(
            $actor,
            $organization->id,
            $email,
            $roleCode,
            $expiresInDays,
            $ipAddress,
            $userAgent
        );
    }

    public function updateUserStatus(User $actor, int $organizationId, int $userId, string $status): User
    {
        $organization = $this->authorizedOrganization($actor, $organizationId);
        $user = $this->mustUserInOrganization($organization, $userId);
        $this->assertCanManageUser($actor, $organization, $user);

        $before = $user->toArray();
        $updated = $this->userRepository->update($user, [
            'status' => User::statusCodeFromString($status),
        ]);

        $this->audit($actor, $organization->tenant_id, 'organization.user.status', $updated->id, $before, $updated->toArray());

        return $updated->fresh(['roleModel', 'organizationAssignment.organization']);
    }

    public function resetUserPassword(User $actor, int $organizationId, int $userId, ?string $ipAddress, ?string $userAgent): void
    {
        $organization = $this->authorizedOrganization($actor, $organizationId);
        $user = $this->mustUserInOrganization($organization, $userId);
        $this->assertCanManageUser($actor, $organization, $user);

        $token = Password::broker()->createToken($user);
        $user->sendPasswordResetNotification($token);

        $this->audit($actor, $organization->tenant_id, 'organization.user.password_reset', $user->id, null, [
            'email' => $user->email,
        ], $ipAddress, $userAgent);
    }

    private function authorizedOrganization(User $actor, int $organizationId): Organization
    {
        $organization = $this->organizationRepository->findById($organizationId);
        if (! $organization) {
            throw new ModelNotFoundException(DomainConstants::MSG_ORGANIZATION_NOT_FOUND);
        }

        if ($actor->isGlobalAdmin()) {
            return $organization;
        }

        if ((int) $organization->tenant_id !== (int) $actor->tenant_id) {
            throw ValidationException::withMessages([
                'organization_id' => [DomainConstants::MSG_UNAUTHORIZED_SCOPE],
            ]);
        }

        if ($actor->isCompanyAdmin()) {
            return $organization;
        }

        if ($actor->isPartnerAdmin()) {
            $tree = $this->organizationRepository->channelTreeOrganizationIds((int) ($actor->primaryOrganizationId() ?? 0));
            if ($organization->type === Organization::TYPE_RESELLER && in_array($organization->id, $tree, true)) {
                return $organization;
            }
        }

        if ($actor->isResellerRole() && $actor->currentRoleCode() === Role::CODE_RESELLER_ADMIN) {
            if ((int) $organization->id === (int) ($actor->primaryOrganizationId() ?? 0)) {
                return $organization;
            }
        }

        throw ValidationException::withMessages([
            'organization' => ['You are not allowed to manage users for this organization.'],
        ]);
    }

    private function mustUserInOrganization(Organization $organization, int $userId): User
    {
        $user = User::query()
            ->whereKey($userId)
            ->where('tenant_id', $organization->tenant_id)
            ->whereHas('organizationAssignment', fn ($q) => $q->where('organization_id', $organization->id))
            ->first();

        if (! $user) {
            throw ValidationException::withMessages(['user' => ['User not found in this organization.']]);
        }

        return $user;
    }

    private function assertInvitableRole(Organization $organization, string $roleCode): void
    {
        if ($organization->type === Organization::TYPE_PARTNER && $roleCode !== Role::CODE_PARTNER_ADMIN) {
            throw ValidationException::withMessages([
                'role_code' => ['Partner organizations can only invite partner_admin.'],
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

    private function assertActorCanInviteRole(User $actor, Organization $organization, string $roleCode): void
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return;
        }

        if ($actor->isPartnerAdmin() && $organization->type === Organization::TYPE_RESELLER) {
            return;
        }

        if ($actor->currentRoleCode() === Role::CODE_RESELLER_ADMIN
            && (int) $organization->id === (int) ($actor->primaryOrganizationId() ?? 0)
            && $roleCode !== Role::CODE_RESELLER_ADMIN) {
            return;
        }

        throw ValidationException::withMessages([
            'organization' => ['You are not allowed to invite users for this organization.'],
        ]);
    }

    private function assertCanManageUser(User $actor, Organization $organization, User $target): void
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return;
        }

        if ($actor->isPartnerAdmin() && $organization->type === Organization::TYPE_RESELLER) {
            return;
        }

        if ($actor->currentRoleCode() === Role::CODE_RESELLER_ADMIN
            && (int) $organization->id === (int) ($actor->primaryOrganizationId() ?? 0)
            && $target->currentRoleCode() !== Role::CODE_RESELLER_ADMIN) {
            return;
        }

        throw ValidationException::withMessages([
            'user' => ['You are not allowed to manage this user.'],
        ]);
    }

    private function audit(
        User $actor,
        int $tenantId,
        string $action,
        int $entityId,
        ?array $before,
        ?array $after,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void {
        $this->auditLogRepository->create([
            'tenant_id' => $tenantId,
            'user_id' => $actor->id,
            'module' => 'organization',
            'action' => $action,
            'entity_type' => 'user',
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }
}
