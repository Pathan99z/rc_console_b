<?php

namespace App\Http\Controllers\Api\Prm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Prm\PayoutBatchService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutBatchController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PayoutBatchService $batchService) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payout_ids' => ['required', 'array', 'min:1'],
            'payout_ids.*' => ['integer'],
        ]);

        $batch = $this->batchService->create($request->user(), $data['payout_ids'], $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_BATCH_CREATED, [
            'batch' => [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'status' => $batch->status,
                'total_amount' => (float) $batch->total_amount,
                'payout_count' => $batch->items->count(),
            ],
        ], 201);
    }

    public function show(Request $request, int $batchId): JsonResponse
    {
        $batch = $this->batchService->find($request->user(), $batchId);

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_BATCH_FETCHED, [
            'batch' => [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'status' => $batch->status,
                'total_amount' => (float) $batch->total_amount,
                'items' => $batch->items->map(fn ($i) => [
                    'payout_id' => $i->payout_id,
                    'payout_number' => $i->payout?->payout_number,
                    'status' => $i->payout?->status,
                ]),
            ],
        ]);
    }

    public function process(Request $request, int $batchId): JsonResponse
    {
        $batch = $this->batchService->process($request->user(), $batchId, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_BATCH_UPDATED, ['batch' => ['id' => $batch->id, 'status' => $batch->status]]);
    }

    public function markPaid(Request $request, int $batchId): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['required', 'string'],
            'remittance_reference' => ['required', 'string', 'max:128'],
            'payment_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string'],
        ]);

        $batch = $this->batchService->markPaid($request->user(), $batchId, $data, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_BATCH_UPDATED, ['batch' => ['id' => $batch->id, 'status' => $batch->status]]);
    }
}
