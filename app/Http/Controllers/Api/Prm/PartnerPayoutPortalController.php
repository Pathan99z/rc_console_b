<?php

namespace App\Http\Controllers\Api\Prm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Payout;
use App\Services\Prm\PayoutGenerationService;
use App\Services\Prm\PayoutStatementService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerPayoutPortalController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PayoutGenerationService $generationService,
        private readonly PayoutStatementService $statementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->generationService->listForActor($request->user(), $request->query(), (int) $request->input('per_page', 15));

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_FETCHED, [
            'items' => collect($items->items())->map(fn (Payout $p) => [
                'id' => $p->id,
                'payout_number' => $p->payout_number,
                'status' => $p->status,
                'net_amount' => (float) $p->net_amount,
                'paid_at' => $p->paid_at?->toIso8601String(),
            ]),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, int $payoutId): JsonResponse
    {
        $payout = $this->generationService->findForActor($request->user(), $payoutId);

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_FETCHED, [
            'payout' => [
                'id' => $payout->id,
                'payout_number' => $payout->payout_number,
                'status' => $payout->status,
                'gross_amount' => (float) $payout->gross_amount,
                'net_amount' => (float) $payout->net_amount,
                'payment_method' => $payout->payment_method,
                'remittance_reference' => $payout->remittance_reference,
                'paid_at' => $payout->paid_at?->toIso8601String(),
                'has_payment_proof' => $payout->supporting_document_path !== null,
                'payment_proof_url' => $payout->supporting_document_path ? url("/api/prm/payouts/{$payout->id}/proof") : null,
            ],
        ]);
    }

    public function statements(Request $request): JsonResponse
    {
        $items = $this->generationService->listForActor($request->user(), ['status' => Payout::STATUS_PAID], 50);

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_STATEMENT, [
            'statements' => collect($items->items())->map(fn (Payout $p) => [
                'payout_id' => $p->id,
                'payout_number' => $p->payout_number,
                'net_amount' => (float) $p->net_amount,
                'paid_at' => $p->paid_at?->toIso8601String(),
            ]),
        ]);
    }
}
