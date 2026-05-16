<?php

namespace App\Support\Tasks;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Repositories\OrganizationRepository;
use App\Services\Auth\AccessScopeService;
use App\Services\Auth\PermissionResolverService;
use App\Support\DomainConstants;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class TaskAccessScope
{
    public function __construct(
        private readonly AccessScopeService $accessScopeService,
        private readonly OrganizationRepository $organizationRepository,
        private readonly PermissionResolverService $permissionResolver,
    ) {}

    public function hasTenantWideAccess(User $actor): bool
    {
        return $actor->isGlobalAdmin()
            || $actor->isCompanyAdmin()
            || $actor->isFinanceAdmin()
            || $this->permissionResolver->can($actor, 'tasks.manage_all');
    }

    /**
     * @return list<int>
     */
    public function visibleOrganizationIds(User $actor): array
    {
        if ($this->hasTenantWideAccess($actor)) {
            return [];
        }

        if ($actor->isPartnerChannelUser()) {
            return $this->accessScopeService->visibleChannelOrgIds($actor);
        }

        if ($actor->isResellerRole()) {
            $orgId = (int) ($actor->primaryOrganizationId() ?? 0);

            return $orgId > 0 ? [$orgId] : [];
        }

        return [];
    }

    public function assertCanViewTask(User $actor, Task $task): void
    {
        if ($actor->isGlobalAdmin()) {
            return;
        }

        if ((int) $task->tenant_id !== (int) $actor->tenant_id) {
            throw new ModelNotFoundException('Task not found.');
        }

        if ($this->hasTenantWideAccess($actor)) {
            return;
        }

        if ((int) $task->assignee_user_id === (int) $actor->id
            || (int) $task->created_by_user_id === (int) $actor->id) {
            return;
        }

        if ($this->canViewViaTeam($actor, $task)) {
            return;
        }

        $visibleOrgs = $this->visibleOrganizationIds($actor);
        if ($visibleOrgs !== [] && $task->scope_organization_id !== null
            && in_array((int) $task->scope_organization_id, $visibleOrgs, true)) {
            return;
        }

        throw new ModelNotFoundException('Task not found.');
    }

    public function assertCanManageTask(User $actor, Task $task): void
    {
        $this->assertCanViewTask($actor, $task);

        if ($this->hasTenantWideAccess($actor)) {
            return;
        }

        if ($this->permissionResolver->can($actor, 'tasks.manage_all')) {
            return;
        }

        if ((int) $task->created_by_user_id === (int) $actor->id) {
            return;
        }

        if ((int) $task->assignee_user_id === (int) $actor->id) {
            return;
        }

        if ($actor->isPartnerAdmin() || $actor->currentRoleCode() === Role::CODE_RESELLER_ADMIN) {
            $visibleOrgs = $this->visibleOrganizationIds($actor);
            if ($task->scope_organization_id !== null
                && in_array((int) $task->scope_organization_id, $visibleOrgs, true)) {
                return;
            }
        }

        if ($actor->currentRoleCode() === Role::CODE_PARTNER_SALES_MANAGER
            || $actor->currentRoleCode() === Role::CODE_RESELLER_SALES_MANAGER) {
            $visibleOrgs = $this->visibleOrganizationIds($actor);
            if ($task->scope_organization_id !== null
                && in_array((int) $task->scope_organization_id, $visibleOrgs, true)) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'task' => [DomainConstants::MSG_UNAUTHORIZED_SCOPE],
        ]);
    }

    public function assertCanAssignTask(User $actor, User $assignee, ?Organization $scopeOrg = null): void
    {
        if (! $this->permissionResolver->can($actor, 'tasks.assign')
            && (int) $assignee->id !== (int) $actor->id) {
            throw ValidationException::withMessages([
                'assignee_user_id' => ['You are not allowed to assign tasks to other users.'],
            ]);
        }

        if ($actor->isGlobalAdmin()) {
            return;
        }

        if ((int) $assignee->tenant_id !== (int) $actor->tenant_id) {
            throw ValidationException::withMessages([
                'assignee_user_id' => ['Assignee must belong to the same tenant.'],
            ]);
        }

        if ($assignee->status !== User::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'assignee_user_id' => ['Assignee must be an active user.'],
            ]);
        }

        if ($this->hasTenantWideAccess($actor)) {
            return;
        }

        $assigneeOrgId = (int) ($assignee->primaryOrganizationId() ?? 0);

        if ($actor->isPartnerChannelUser() && ! $actor->isResellerRole()) {
            $tree = $this->organizationRepository->channelTreeOrganizationIds(
                (int) ($actor->primaryOrganizationId() ?? 0)
            );
            if ($assigneeOrgId > 0 && ! in_array($assigneeOrgId, $tree, true)) {
                throw ValidationException::withMessages([
                    'assignee_user_id' => ['Assignee is outside your partner organization tree.'],
                ]);
            }
            if ($assigneeOrgId === 0) {
                throw ValidationException::withMessages([
                    'assignee_user_id' => ['You cannot assign tasks to internal company users.'],
                ]);
            }

            return;
        }

        if ($actor->isResellerRole()) {
            $ownOrg = (int) ($actor->primaryOrganizationId() ?? 0);
            if ($assigneeOrgId !== $ownOrg) {
                throw ValidationException::withMessages([
                    'assignee_user_id' => ['Assignee must belong to your reseller organization.'],
                ]);
            }

            return;
        }

        if ((int) $assignee->id === (int) $actor->id) {
            return;
        }

        if ((int) $actor->data_scope === DomainConstants::DATA_SCOPE_TEAM
            && $actor->team_id !== null
            && (int) $assignee->team_id === (int) $actor->team_id) {
            return;
        }

        throw ValidationException::withMessages([
            'assignee_user_id' => ['You are not allowed to assign tasks to this user.'],
        ]);
    }

    public function assertScopeOrganizationAllowed(User $actor, ?int $scopeOrganizationId): void
    {
        if ($scopeOrganizationId === null) {
            if ($this->hasTenantWideAccess($actor)) {
                return;
            }

            throw ValidationException::withMessages([
                'scope_organization_id' => ['Organization scope is required for channel users.'],
            ]);
        }

        $org = $this->organizationRepository->findByIdInTenant($scopeOrganizationId, (int) $actor->tenant_id);
        if (! $org) {
            throw ValidationException::withMessages([
                'scope_organization_id' => ['Invalid organization scope.'],
            ]);
        }

        if ($this->hasTenantWideAccess($actor)) {
            return;
        }

        $visible = $this->visibleOrganizationIds($actor);
        if ($visible === [] || ! in_array((int) $org->id, $visible, true)) {
            throw ValidationException::withMessages([
                'scope_organization_id' => [DomainConstants::MSG_UNAUTHORIZED_SCOPE],
            ]);
        }
    }

    public function canUseListView(User $actor, string $view): bool
    {
        return match ($view) {
            'tenant', 'organization_tree' => $this->hasTenantWideAccess($actor)
                || ($view === 'organization_tree' && $actor->isPartnerChannelUser() && ! $actor->isResellerRole()),
            'organization' => $this->hasTenantWideAccess($actor)
                || $actor->isPartnerPortalEligible()
                || $actor->currentRoleCode() === Role::CODE_USER,
            'created_by_me', 'my' => true,
            default => false,
        };
    }

    /**
     * @param  Builder<Task>  $query
     */
    public function applyListScope(Builder $query, User $actor, string $view = 'my'): void
    {
        if ($actor->isGlobalAdmin()) {
            return;
        }

        $query->where('tenant_id', $actor->tenant_id);

        if (! $this->canUseListView($actor, $view)) {
            $query->whereRaw('1 = 0');

            return;
        }

        match ($view) {
            'tenant' => null,
            'created_by_me' => $query->where('created_by_user_id', $actor->id),
            'organization' => $this->applyOrganizationView($query, $actor, false),
            'organization_tree' => $this->applyOrganizationView($query, $actor, true),
            default => $this->applyMyView($query, $actor),
        };
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function applyMyView(Builder $query, User $actor): void
    {
        $query->where(function (Builder $inner) use ($actor): void {
            $inner->where('assignee_user_id', $actor->id)
                ->orWhere('created_by_user_id', $actor->id);

            if ((int) $actor->data_scope === DomainConstants::DATA_SCOPE_TEAM && $actor->team_id !== null) {
                $teamUserIds = User::query()
                    ->where('tenant_id', $actor->tenant_id)
                    ->where('team_id', $actor->team_id)
                    ->pluck('id')
                    ->all();
                if ($teamUserIds !== []) {
                    $inner->orWhereIn('assignee_user_id', $teamUserIds);
                }
            }
        });
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function applyOrganizationView(Builder $query, User $actor, bool $tree): void
    {
        if ($this->hasTenantWideAccess($actor) && $tree) {
            return;
        }

        $orgIds = $tree && $actor->isPartnerChannelUser() && ! $actor->isResellerRole()
            ? $this->organizationRepository->channelTreeOrganizationIds((int) ($actor->primaryOrganizationId() ?? 0))
            : $this->visibleOrganizationIds($actor);

        if ($orgIds === []) {
            $primary = (int) ($actor->primaryOrganizationId() ?? 0);
            if ($primary > 0) {
                $orgIds = [$primary];
            }
        }

        if ($orgIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('scope_organization_id', $orgIds);
    }

    private function canViewViaTeam(User $actor, Task $task): bool
    {
        if ((int) $actor->data_scope !== DomainConstants::DATA_SCOPE_TEAM || $actor->team_id === null) {
            return false;
        }

        if ($task->assignee_user_id === null) {
            return false;
        }

        $assignee = User::query()->find($task->assignee_user_id);
        if (! $assignee) {
            return false;
        }

        return (int) $assignee->team_id === (int) $actor->team_id;
    }
}
