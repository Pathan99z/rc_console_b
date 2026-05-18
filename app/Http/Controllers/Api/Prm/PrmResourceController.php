<?php

namespace App\Http\Controllers\Api\Prm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Prm\ListPrmAdminResourcesRequest;
use App\Http\Requests\Prm\PatchPrmResourceStatusRequest;
use App\Http\Requests\Prm\PrmResourceAnalyticsRequest;
use App\Http\Requests\Prm\StorePrmResourceRequest;
use App\Http\Requests\Prm\UpdatePrmResourceRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Collateral;
use App\Services\Prm\PrmResourceAnalyticsService;
use App\Services\Prm\PrmResourceManagementService;
use App\Support\DomainConstants;
use App\Support\Storage\EnterpriseStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrmResourceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PrmResourceManagementService $managementService,
        private readonly PrmResourceAnalyticsService $analyticsService,
        private readonly EnterpriseStorage $storage,
    ) {}

    public function analytics(PrmResourceAnalyticsRequest $request): JsonResponse
    {
        $data = $this->analyticsService->summarize($request->user(), $request->validated());

        return $this->successResponse(DomainConstants::MSG_PRM_RESOURCE_ANALYTICS, $data);
    }

    public function index(ListPrmAdminResourcesRequest $request): JsonResponse
    {
        $items = $this->managementService->listForAdmin(
            $request->user(),
            $request->validated(),
            (int) ($request->validated('per_page') ?? 15)
        );

        return $this->successResponse(DomainConstants::MSG_PRM_RESOURCE_ADMIN_LISTED, [
            'items' => collect($items->items())->map(fn (Collateral $r) => $this->adminResourcePayload($r)),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(StorePrmResourceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        if (isset($validated['metadata']) && is_string($validated['metadata'])) {
            $decoded = json_decode($validated['metadata'], true);
            $validated['metadata'] = is_array($decoded) ? $decoded : null;
        }

        $row = $this->managementService->store(
            $request->user(),
            $validated,
            $request->file('file'),
            $request
        );

        return $this->successResponse(DomainConstants::MSG_PRM_RESOURCE_CREATED, [
            'resource' => $this->adminResourcePayload($row),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($request->user()->isGlobalAdmin()) {
            $request->validate([
                'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            ]);
        }

        $row = $this->managementService->showForAdmin(
            $request->user(),
            $id,
            $request->only(['tenant_id'])
        );

        return $this->successResponse(DomainConstants::MSG_PRM_RESOURCE_SHOWN, [
            'resource' => $this->adminResourcePayload($row),
        ]);
    }

    public function update(UpdatePrmResourceRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();
        if (isset($validated['metadata']) && is_string($validated['metadata'])) {
            $decoded = json_decode($validated['metadata'], true);
            $validated['metadata'] = is_array($decoded) ? $decoded : null;
        }

        $row = $this->managementService->update(
            $request->user(),
            $id,
            $validated,
            $request->file('file'),
            $request
        );

        return $this->successResponse(DomainConstants::MSG_PRM_RESOURCE_UPDATED, [
            'resource' => $this->adminResourcePayload($row),
        ]);
    }

    public function updateStatus(PatchPrmResourceStatusRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();
        $row = $this->managementService->updateStatus(
            $request->user(),
            $id,
            (string) $data['status'],
            $request,
            $request->only(['tenant_id'])
        );

        return $this->successResponse(DomainConstants::MSG_PRM_RESOURCE_STATUS_UPDATED, [
            'resource' => $this->adminResourcePayload($row),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($request->user()->isGlobalAdmin()) {
            $request->validate([
                'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            ]);
        }

        $this->managementService->delete($request->user(), $id, $request, $request->only(['tenant_id']));

        return $this->successResponse(DomainConstants::MSG_PRM_RESOURCE_DELETED);
    }

    /**
     * @return array<string, mixed>
     */
    private function adminResourcePayload(Collateral $row): array
    {
        $downloadCount = (int) ($row->download_count ?? 0);

        return [
            'id' => $row->id,
            'title' => $row->name,
            'description' => $row->description,
            'resource_category' => $row->resource_category,
            'product_id' => $row->product_id,
            'product_name' => $row->product?->name,
            'partner_visible' => (bool) $row->partner_visible,
            'reseller_visible' => (bool) $row->reseller_visible,
            'status' => $row->status ?? Collateral::STATUS_ACTIVE,
            'metadata' => $row->metadata ?? (object) [],
            'download_count' => $downloadCount,
            'file' => [
                'file_type' => $row->file_type,
                'file_size' => $row->file_size,
            ],
            'signed_url' => $this->signedUrlForPrmResource((string) $row->file_key),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }

    private function signedUrlForPrmResource(string $fileKey): string
    {
        return $this->storage->signedUrl(
            $fileKey,
            (int) config('enterprise_storage.collateral_signed_url_minutes', 10),
            EnterpriseStorage::PURPOSE_COLLATERAL
        );
    }
}
