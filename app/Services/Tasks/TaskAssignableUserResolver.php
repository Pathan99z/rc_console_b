<?php

namespace App\Services\Tasks;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Repositories\OrganizationRepository;
use App\Support\DomainConstants;
use App\Support\Tasks\TaskAccessScope;
use Illuminate\Support\Collection;

class TaskAssignableUserResolver
{
    public function __construct(
        private readonly TaskAccessScope $accessScope,
        private readonly OrganizationRepository $organizationRepository,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function getAssignableUsers(User $actor, ?string $search = null): Collection
    {
        $query = User::query()
            ->with(['roleModel', 'organizationAssignment.organization'])
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('name');

        if ($actor->isGlobalAdmin()) {
            // all users when no tenant filter in tests - restrict by tenant when set
            if ($actor->tenant_id !== null) {
                $query->where('tenant_id', $actor->tenant_id);
            }
        } else {
            $query->where('tenant_id', $actor->tenant_id);
        }

        if ($this->accessScope->hasTenantWideAccess($actor)) {
            // all tenant users
        } elseif ($actor->isPartnerChannelUser() && ! $actor->isResellerRole()) {
            $tree = $this->organizationRepository->channelTreeOrganizationIds(
                (int) ($actor->primaryOrganizationId() ?? 0)
            );
            $query->whereHas('organizationAssignment', fn ($q) => $q->whereIn('organization_id', $tree));
        } elseif ($actor->isResellerRole()) {
            $orgId = (int) ($actor->primaryOrganizationId() ?? 0);
            $query->whereHas('organizationAssignment', fn ($q) => $q->where('organization_id', $orgId));
        } else {
            $query->where(function ($inner) use ($actor): void {
                $inner->where('id', $actor->id);
                if ((int) $actor->data_scope === DomainConstants::DATA_SCOPE_TEAM && $actor->team_id !== null) {
                    $inner->orWhere('team_id', $actor->team_id);
                }
            });
        }

        if ($search !== null && trim($search) !== '') {
            $keyword = '%'.trim($search).'%';
            $query->where(function ($q) use ($keyword): void {
                $q->where('name', 'like', $keyword)
                    ->orWhere('email', 'like', $keyword);
            });
        }

        return $query->limit(200)->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function formatAssignableUsers(Collection $users): array
    {
        return $users->map(function (User $user): array {
            $org = $user->organizationAssignment?->organization;

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->currentRoleCode(),
                'organization' => $org ? [
                    'id' => $org->id,
                    'type' => $org->type,
                    'display_name' => $org->display_name,
                ] : null,
            ];
        })->values()->all();
    }
}
