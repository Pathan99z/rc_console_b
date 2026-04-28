<?php

namespace App\Repositories;

use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PipelineRepository
{
    public function paginateFiltered(User $actor, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return Pipeline::query()
            ->with('stages')
            ->when(! $actor->isGlobalAdmin(), fn ($q) => $q->where('tenant_id', $actor->tenant_id))
            ->when($actor->isGlobalAdmin() && isset($filters['tenant_id']), fn ($q) => $q->where('tenant_id', (int) $filters['tenant_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', (int) $filters['status']))
            ->when(isset($filters['search']), fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%'))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findById(int $id): ?Pipeline
    {
        return Pipeline::query()->with('stages')->find($id);
    }

    public function create(array $data): Pipeline
    {
        return Pipeline::query()->create($data);
    }

    public function update(Pipeline $pipeline, array $data): Pipeline
    {
        $pipeline->update($data);

        return $pipeline->refresh();
    }
}
