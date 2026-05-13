<?php

namespace App\Services\User;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\UserOrganizationAssignment;
use App\Repositories\AuditLogRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Support\DomainConstants;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly RoleRepository $roleRepository,
        private readonly AuditLogRepository $auditLogRepository,
    ) {}

    public function listUsers(int $perPage = 15): LengthAwarePaginator
    {
        return $this->userRepository->paginateForCurrentScope($perPage);
    }

    public function createUser(User $actor, array $payload): User
    {
        $tenantId = $this->resolveTenantId($actor, $payload);
        $role = $this->resolveRole($actor, $payload);
        $roleId = $this->resolveRoleId($role);
        $teamId = $this->resolveTeamId($tenantId, $payload);
        $organizationId = $this->resolveOrganizationId($actor, $tenantId, $role, $payload);
        $dataScope = $this->resolveDataScope($payload);

        $createdUser = $this->userRepository->create([
            'tenant_id' => $tenantId,
            'team_id' => $teamId,
            'manager_id' => $payload['manager_id'] ?? null,
            'role_id' => $roleId,
            'role' => $role,
            'status' => User::statusCodeFromString($payload['status'] ?? 'active'),
            'data_scope' => $dataScope,
            'name' => $payload['name'],
            'email' => strtolower($payload['email']),
            'password' => Hash::make($payload['password']),
        ]);

        if ($organizationId !== null) {
            UserOrganizationAssignment::query()->updateOrCreate(
                ['user_id' => $createdUser->id],
                ['organization_id' => $organizationId]
            );
        }

        if ($createdUser instanceof MustVerifyEmail && ! $createdUser->hasVerifiedEmail()) {
            $createdUser->sendEmailVerificationNotification();
        }

        return $createdUser;
    }

    public function updateUserStatus(User $actor, int $userId, string $status): User
    {
        $user = $this->userRepository->findById($userId);
        if (! $user) {
            throw ValidationException::withMessages([
                'user' => ['User not found.'],
            ]);
        }

        $this->ensureSameTenantAccess($actor, $user);

        return $this->userRepository->update($user, ['status' => User::statusCodeFromString($status)]);
    }

    public function updateUserRole(User $actor, int $userId, string $role): User
    {
        if (! $actor->isGlobalAdmin()) {
            throw ValidationException::withMessages([
                'role' => ['Only global admin can update user roles.'],
            ]);
        }

        $user = $this->userRepository->findById($userId);
        if (! $user) {
            throw ValidationException::withMessages([
                'user' => ['User not found.'],
            ]);
        }

        if ($user->isGlobalAdmin()) {
            throw ValidationException::withMessages([
                'role' => ['Global admin role cannot be updated from this endpoint.'],
            ]);
        }

        $before = $user->toArray();
        $updated = $this->userRepository->update($user, [
            'role_id' => $this->resolveRoleId($role),
            'role' => $role,
        ]);

        $this->auditLogRepository->create([
            'tenant_id' => $updated->tenant_id,
            'user_id' => $actor->id,
            'module' => 'user',
            'action' => DomainConstants::LOG_USER_ROLE_CHANGED,
            'entity_type' => 'user',
            'entity_id' => $updated->id,
            'before' => $before,
            'after' => $updated->toArray(),
            'ip_address' => null,
            'user_agent' => null,
        ]);

        return $updated;
    }

    private function resolveTenantId(User $actor, array $payload): ?int
    {
        if ($actor->isGlobalAdmin()) {
            return $payload['tenant_id'] ?? null;
        }

        return $actor->tenant_id;
    }

    private function resolveRole(User $actor, array $payload): string
    {
        if ($actor->isGlobalAdmin()) {
            return $payload['role'] ?? 'user';
        }

        $requestedRole = $payload['role'] ?? Role::CODE_USER;
        if (! in_array($requestedRole, [
            Role::CODE_USER,
            Role::CODE_PARTNER_ADMIN,
            Role::CODE_PARTNER_SALES_MANAGER,
            Role::CODE_PARTNER_SALES_CONSULTANT,
            Role::CODE_RESELLER_ADMIN,
            Role::CODE_RESELLER_SALES_CONSULTANT,
        ], true)) {
            throw ValidationException::withMessages([
                'role' => ['Company admin cannot create the selected role.'],
            ]);
        }

        return $requestedRole;
    }

    private function ensureSameTenantAccess(User $actor, User $target): void
    {
        if ($actor->isGlobalAdmin()) {
            return;
        }

        if ($target->tenant_id !== $actor->tenant_id) {
            throw ValidationException::withMessages([
                'user' => ['Cross-tenant access is not allowed.'],
            ]);
        }
    }

    private function resolveRoleId(string $role): int
    {
        $roleModel = $this->roleRepository->findByCode($role);
        if (! $roleModel) {
            throw ValidationException::withMessages([
                'role' => ['Invalid role selected.'],
            ]);
        }

        return $roleModel->id;
    }

    private function resolveDataScope(array $payload): int
    {
        $scope = $payload['data_scope'] ?? 'self';

        return match ($scope) {
            'self' => DomainConstants::DATA_SCOPE_SELF,
            'team' => DomainConstants::DATA_SCOPE_TEAM,
            default => throw ValidationException::withMessages([
                'data_scope' => [DomainConstants::MSG_INVALID_SCOPE],
            ]),
        };
    }

    private function resolveTeamId(?int $tenantId, array $payload): ?int
    {
        $teamId = isset($payload['team_id']) ? (int) $payload['team_id'] : null;

        // Backward-compat: if old manager_id is still sent, infer team from that manager.
        if (! $teamId && isset($payload['manager_id'])) {
            $manager = $this->userRepository->findById((int) $payload['manager_id']);
            if ($manager) {
                $teamId = $manager->team_id;
            }
        }

        if (! $teamId) {
            return null;
        }

        $team = Team::query()->find($teamId);
        if (! $team || (int) $team->tenant_id !== (int) $tenantId) {
            throw ValidationException::withMessages([
                'team_id' => [DomainConstants::MSG_INVALID_TEAM],
            ]);
        }

        return $teamId;
    }

    private function resolveOrganizationId(User $actor, int $tenantId, string $role, array $payload): ?int
    {
        $organizationId = isset($payload['organization_id']) ? (int) $payload['organization_id'] : null;
        $isPartnerOrResellerRole = in_array($role, [
            Role::CODE_PARTNER_ADMIN,
            Role::CODE_PARTNER_SALES_MANAGER,
            Role::CODE_PARTNER_SALES_CONSULTANT,
            Role::CODE_RESELLER_ADMIN,
            Role::CODE_RESELLER_SALES_CONSULTANT,
        ], true);

        if ($organizationId === null && $isPartnerOrResellerRole) {
            throw ValidationException::withMessages([
                'organization_id' => ['organization_id is required for partner and reseller roles.'],
            ]);
        }

        if ($organizationId === null) {
            return null;
        }

        $organization = Organization::query()->find($organizationId);
        if (! $organization || (int) $organization->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'organization_id' => ['Invalid organization selected for tenant.'],
            ]);
        }

        $actorOrgId = $actor->primaryOrganizationId();
        if (
            $actor->currentRoleCode() === Role::CODE_PARTNER_ADMIN
            && (int) $organization->id !== (int) $actorOrgId
            && (int) $organization->parent_organization_id !== (int) $actorOrgId
        ) {
            throw ValidationException::withMessages([
                'organization_id' => ['Partner admin can only assign users within own partner and child reseller organizations.'],
            ]);
        }

        if (in_array($role, [Role::CODE_PARTNER_ADMIN, Role::CODE_PARTNER_SALES_MANAGER, Role::CODE_PARTNER_SALES_CONSULTANT], true)
            && $organization->type !== Organization::TYPE_PARTNER) {
            throw ValidationException::withMessages([
                'organization_id' => ['Partner roles require partner organization assignment.'],
            ]);
        }

        if (in_array($role, [Role::CODE_RESELLER_ADMIN, Role::CODE_RESELLER_SALES_CONSULTANT], true)
            && $organization->type !== Organization::TYPE_RESELLER) {
            throw ValidationException::withMessages([
                'organization_id' => ['Reseller roles require reseller organization assignment.'],
            ]);
        }

        return $organizationId;
    }
}
