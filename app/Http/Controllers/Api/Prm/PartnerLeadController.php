<?php

namespace App\Http\Controllers\Api\Prm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Prm\PartnerLeadStoreRequest;
use App\Http\Requests\Prm\PartnerLeadUpdateRequest;
use App\Http\Resources\PartnerLeadResource;
use App\Http\Responses\ApiResponse;
use App\Services\Prm\PartnerLeadService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerLeadController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PartnerLeadService $partnerLeadService) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->partnerLeadService->list($request->user(), (int) ($request->input('per_page', 15)));

        return $this->successResponse(DomainConstants::MSG_PRM_LEAD_FETCHED, [
            'items' => PartnerLeadResource::collection($items->items()),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(PartnerLeadStoreRequest $request): JsonResponse
    {
        $lead = $this->partnerLeadService->create($request->user(), $request->validated(), $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_PRM_LEAD_CREATED, [
            'lead' => new PartnerLeadResource($lead),
        ], 201);
    }

    public function update(PartnerLeadUpdateRequest $request, int $leadId): JsonResponse
    {
        $lead = $this->partnerLeadService->update($request->user(), $leadId, $request->validated(), $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_PRM_LEAD_UPDATED, [
            'lead' => new PartnerLeadResource($lead),
        ]);
    }
}
