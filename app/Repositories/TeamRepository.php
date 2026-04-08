<?php

namespace App\Repositories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TeamRepository
{
    public function paginateFiltered(User $actor, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return Team::query()
            ->when(! $actor->isGlobalAdmin(), fn ($q) => $q->where('tenant_id', $actor->tenant_id))
            ->when($actor->isGlobalAdmin() && isset($filters['tenant_id']), fn ($q) => $q->where('tenant_id', (int) $filters['tenant_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', (int) $filters['status']))
            ->when(isset($filters['search']), fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%'))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findById(int $id): ?Team
    {
        return Team::query()->find($id);
    }

    public function create(array $data): Team
    {
        return Team::query()->create($data);
    }

    public function update(Team $team, array $data): Team
    {
        $team->update($data);

        return $team->refresh();
    }
}
