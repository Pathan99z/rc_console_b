<?php

namespace App\Http\Controllers\Api\Prm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Prm\PayoutDisputeService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutDisputeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PayoutDisputeService $service) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->service->listForActor($request->user(), (int) $request->input('per_page', 15));

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_DISPUTE_FETCHED, [
            'items' => $items->items(),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payout_id' => ['required', 'integer'],
            'description' => ['required', 'string'],
        ]);

        $dispute = $this->service->create($request->user(), $data, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_DISPUTE_CREATED, ['dispute' => $dispute], 201);
    }

    public function resolve(Request $request, int $disputeId): JsonResponse
    {
        $data = $request->validate(['resolution' => ['required', 'string']]);
        $dispute = $this->service->resolve($request->user(), $disputeId, $data['resolution'], $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_DISPUTE_UPDATED, ['dispute' => $dispute]);
    }

    public function reject(Request $request, int $disputeId): JsonResponse
    {
        $data = $request->validate(['resolution' => ['required', 'string']]);
        $dispute = $this->service->reject($request->user(), $disputeId, $data['resolution'], $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_DISPUTE_UPDATED, ['dispute' => $dispute]);
    }
}
