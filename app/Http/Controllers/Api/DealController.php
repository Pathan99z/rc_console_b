<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Deal\CreateDealRequest;
use App\Http\Requests\Deal\ListDealsRequest;
use App\Http\Requests\Deal\MoveDealStageRequest;
use App\Http\Requests\Deal\UpdateDealRequest;
use App\Http\Requests\Deal\UpdateDealStatusRequest;
use App\Http\Resources\DealResource;
use App\Http\Responses\ApiResponse;
use App\Services\Deal\DealManagementService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DealManagementService $service)
    {
    }

    public function index(ListDealsRequest $request): JsonResponse
    {
        $items = $this->service->listDeals(
            $request->user(),
            $request->validated(),
            (int) ($request->validated('per_page') ?? 15)
        );

        return $this->successResponse(DomainConstants::MSG_DEAL_FETCHED, [
            'items' => DealResource::collection($items->items()),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(CreateDealRequest $request): JsonResponse
    {
        $deal = $this->service->createDeal($request->user(), $request->validated());

        return $this->successResponse(DomainConstants::MSG_DEAL_CREATED, ['deal' => new DealResource($deal)], 201);
    }

    public function show(Request $request, int $dealId): JsonResponse
    {
        $deal = $this->service->getDeal($request->user(), $dealId);

        return $this->successResponse(DomainConstants::MSG_DEAL_FETCHED, ['deal' => new DealResource($deal)]);
    }

    public function update(UpdateDealRequest $request, int $dealId): JsonResponse
    {
        $deal = $this->service->updateDeal($request->user(), $dealId, $request->validated());

        return $this->successResponse(DomainConstants::MSG_DEAL_UPDATED, ['deal' => new DealResource($deal)]);
    }

    public function destroy(Request $request, int $dealId): JsonResponse
    {
        $this->service->deleteDeal($request->user(), $dealId);

        return $this->successResponse(DomainConstants::MSG_DEAL_DELETED);
    }

    public function moveStage(MoveDealStageRequest $request, int $dealId): JsonResponse
    {
        $deal = $this->service->moveStage(
            $request->user(),
            $dealId,
            (int) $request->validated('pipeline_stage_id'),
            $request->validated('notes')
        );

        return $this->successResponse(DomainConstants::MSG_DEAL_STAGE_MOVED, ['deal' => new DealResource($deal)]);
    }

    public function updateStatus(UpdateDealStatusRequest $request, int $dealId): JsonResponse
    {
        $deal = $this->service->updateStatus(
            $request->user(),
            $dealId,
            (string) $request->validated('status'),
            $request->validated('notes')
        );

        return $this->successResponse(DomainConstants::MSG_DEAL_STATUS_UPDATED, ['deal' => new DealResource($deal)]);
    }
}
