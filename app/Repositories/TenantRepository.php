<?php

namespace App\Repositories;

use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TenantRepository
{
    public function create(array $data): Tenant
    {
        return Tenant::query()->create($data);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Tenant::query()
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findById(int $id): ?Tenant
    {
        return Tenant::query()->find($id);
    }
}
