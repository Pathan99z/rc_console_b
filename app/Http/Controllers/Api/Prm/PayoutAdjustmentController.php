<?php

namespace App\Http\Controllers\Api\Prm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Prm\PayoutAdjustmentService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutAdjustmentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PayoutAdjustmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->service->listForActor($request->user(), (int) $request->input('per_page', 15));

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_ADJUSTMENT_FETCHED, [
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
            'organization_id' => ['required', 'integer'],
            'payout_id' => ['nullable', 'integer'],
            'type' => ['required', 'string', 'in:credit,debit'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency_code' => ['nullable', 'string', 'max:8'],
            'reason' => ['required', 'string'],
            'remarks' => ['nullable', 'string'],
        ]);

        $row = $this->service->create($request->user(), $data, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_ADJUSTMENT_CREATED, ['adjustment' => $row], 201);
    }
}
