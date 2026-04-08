<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Repositories\TenantRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class TenantManagementService
{
    public function __construct(private readonly TenantRepository $tenantRepository)
    {
    }

    public function listTenants(int $perPage = 15): LengthAwarePaginator
    {
        return $this->tenantRepository->paginate($perPage);
    }

    public function updateStatus(int $tenantId, string $status): Tenant
    {
        $tenant = $this->tenantRepository->findById($tenantId);
        if (! $tenant) {
            throw ValidationException::withMessages([
                'tenant' => ['Tenant not found.'],
            ]);
        }

        $tenant->update(['status' => Tenant::statusCodeFromString($status)]);

        return $tenant->refresh();
    }
}
