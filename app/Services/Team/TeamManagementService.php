<?php

namespace App\Services\Team;

use App\Models\Team;
use App\Models\User;
use App\Repositories\TeamRepository;
use App\Support\DomainConstants;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class TeamManagementService
{
    public function __construct(private readonly TeamRepository $teamRepository)
    {
    }

    public function listTeams(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->teamRepository->paginateFiltered($actor, $filters, $perPage);
    }

    public function createTeam(User $actor, array $payload): Team
    {
        if ($actor->isGlobalAdmin()) {
            if (! isset($payload['tenant_id'])) {
                throw ValidationException::withMessages([
                    'tenant_id' => ['tenant_id is required for global admin operations.'],
                ]);
            }
            $payload['tenant_id'] = (int) $payload['tenant_id'];
        } else {
            $payload['tenant_id'] = (int) $actor->tenant_id;
        }

        return $this->teamRepository->create($payload);
    }

    public function updateTeam(User $actor, int $teamId, array $payload): Team
    {
        $team = $this->teamRepository->findById($teamId);
        if (! $team || (! $actor->isGlobalAdmin() && (int) $team->tenant_id !== (int) $actor->tenant_id)) {
            throw new ModelNotFoundException(DomainConstants::MSG_INVALID_TEAM);
        }

        return $this->teamRepository->update($team, $payload);
    }

    public function deleteTeam(User $actor, int $teamId): void
    {
        $team = $this->teamRepository->findById($teamId);
        if (! $team || (! $actor->isGlobalAdmin() && (int) $team->tenant_id !== (int) $actor->tenant_id)) {
            throw new ModelNotFoundException(DomainConstants::MSG_INVALID_TEAM);
        }

        $team->delete();
    }
}
