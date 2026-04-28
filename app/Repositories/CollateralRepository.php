<?php

namespace App\Repositories;

use App\Models\Collateral;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class CollateralRepository
{
    public function paginateFiltered(User $actor, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return Collateral::query()
            ->with(['product', 'createdByUser', 'updatedByUser'])
            ->when(! $actor->isGlobalAdmin(), fn (Builder $q) => $q->where('tenant_id', $actor->tenant_id))
            ->when($actor->isGlobalAdmin() && isset($filters['tenant_id']), fn (Builder $q) => $q->where('tenant_id', (int) $filters['tenant_id']))
            ->when(isset($filters['product_id']), fn (Builder $q) => $q->where('product_id', (int) $filters['product_id']))
            ->when(isset($filters['type']), fn (Builder $q) => $q->where('type', (string) $filters['type']))
            ->when(isset($filters['file_type']), fn (Builder $q) => $q->where('file_type', (string) $filters['file_type']))
            ->when(isset($filters['search']), function (Builder $q) use ($filters): void {
                $search = (string) $filters['search'];
                $q->where('name', 'like', "%{$search}%");
            })
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findById(int $id): ?Collateral
    {
        return Collateral::query()->with(['product', 'createdByUser', 'updatedByUser'])->find($id);
    }

    public function create(array $payload): Collateral
    {
        return Collateral::query()->create($payload);
    }

    public function delete(Collateral $collateral): void
    {
        $collateral->delete();
    }
}
