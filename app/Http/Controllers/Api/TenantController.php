<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ListTenantsRequest;
use App\Http\Requests\Tenant\UpdateTenantStatusRequest;
use App\Http\Resources\TenantResource;
use App\Http\Responses\ApiResponse;
use App\Services\Tenant\TenantManagementService;
use Illuminate\Http\JsonResponse;

class TenantController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantManagementService $tenantManagementService)
    {
    }

    public function index(ListTenantsRequest $request): JsonResponse
    {
        $tenants = $this->tenantManagementService->listTenants(
            (int) ($request->validated('per_page') ?? 15)
        );

        return $this->successResponse('Tenants fetched successfully.', [
            'items' => TenantResource::collection($tenants->items()),
            'pagination' => [
                'current_page' => $tenants->currentPage(),
                'last_page' => $tenants->lastPage(),
                'per_page' => $tenants->perPage(),
                'total' => $tenants->total(),
            ],
        ]);
    }

    public function updateStatus(UpdateTenantStatusRequest $request, int $tenantId): JsonResponse
    {
        $tenant = $this->tenantManagementService->updateStatus($tenantId, $request->validated('status'));

        return $this->successResponse('Tenant status updated successfully.', [
            'tenant' => new TenantResource($tenant),
        ]);
    }
}
