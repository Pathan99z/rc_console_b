<?php

namespace App\Services\User;

use App\Models\User;
use App\Models\Team;
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
    ) {
    }

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

        return $this->userRepository->update($user, [
            'role_id' => $this->resolveRoleId($role),
            'role' => $role,
        ]);
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

        if (($payload['role'] ?? 'user') !== 'user') {
            throw ValidationException::withMessages([
                'role' => ['Company admin can only create normal users.'],
            ]);
        }

        return 'user';
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
}
