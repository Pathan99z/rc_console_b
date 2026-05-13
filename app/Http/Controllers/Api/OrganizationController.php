<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CreateOrganizationRequest;
use App\Http\Requests\Organization\ListOrganizationParentOptionsRequest;
use App\Http\Requests\Organization\ListOrganizationsRequest;
use App\Http\Requests\Organization\OrganizationRejectRequest;
use App\Http\Requests\Organization\UpdateOrganizationRequest;
use App\Http\Requests\Organization\UpdateOrganizationStatusRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Responses\ApiResponse;
use App\Services\Organization\OrganizationManagementService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OrganizationManagementService $service) {}

    public function index(ListOrganizationsRequest $request): JsonResponse
    {
        $items = $this->service->listOrganizations(
            $request->user(),
            $request->validated(),
            (int) ($request->validated('per_page') ?? 15)
        );

        return $this->successResponse(DomainConstants::MSG_ORGANIZATION_FETCHED, [
            'items' => OrganizationResource::collection($items->items()),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function parentOptions(ListOrganizationParentOptionsRequest $request): JsonResponse
    {
        $items = $this->service->listParentOptions($request->user(), $request->validated());

        return $this->successResponse(DomainConstants::MSG_ORGANIZATION_PARENT_OPTIONS_FETCHED, [
            'items' => $items,
        ]);
    }

    public function store(CreateOrganizationRequest $request): JsonResponse
    {
        $organization = $this->service->createOrganization(
            $request->user(),
            $request->validated(),
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_ORGANIZATION_CREATED, [
            'organization' => new OrganizationResource($organization),
        ], 201);
    }

    public function show(Request $request, int $organizationId): JsonResponse
    {
        $organization = $this->service->showOrganization($request->user(), $organizationId);

        return $this->successResponse(DomainConstants::MSG_ORGANIZATION_FETCHED, [
            'organization' => new OrganizationResource($organization),
        ]);
    }

    public function update(UpdateOrganizationRequest $request, int $organizationId): JsonResponse
    {
        $organization = $this->service->updateOrganization(
            $request->user(),
            $organizationId,
            $request->validated(),
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_ORGANIZATION_UPDATED, [
            'organization' => new OrganizationResource($organization),
        ]);
    }

    public function updateStatus(UpdateOrganizationStatusRequest $request, int $organizationId): JsonResponse
    {
        $organization = $this->service->updateStatus(
            $request->user(),
            $organizationId,
            $request->validated('status'),
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_ORGANIZATION_STATUS_UPDATED, [
            'organization' => new OrganizationResource($organization),
        ]);
    }

    public function approve(Request $request, int $organizationId): JsonResponse
    {
        $organization = $this->service->approve(
            $request->user(),
            $organizationId,
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_ORGANIZATION_APPROVED, [
            'organization' => new OrganizationResource($organization),
        ]);
    }

    public function reject(OrganizationRejectRequest $request, int $organizationId): JsonResponse
    {
        $organization = $this->service->reject(
            $request->user(),
            $organizationId,
            $request->validated('reason'),
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_ORGANIZATION_REJECTED, [
            'organization' => new OrganizationResource($organization),
        ]);
    }

    public function suspend(Request $request, int $organizationId): JsonResponse
    {
        $organization = $this->service->suspend(
            $request->user(),
            $organizationId,
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_ORGANIZATION_SUSPENDED, [
            'organization' => new OrganizationResource($organization),
        ]);
    }
}
