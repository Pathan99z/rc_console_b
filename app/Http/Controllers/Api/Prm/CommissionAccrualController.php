<?php

namespace App\Http\Controllers\Api\Prm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Prm\CommissionAccrualService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommissionAccrualController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CommissionAccrualService $commissionAccrualService) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->commissionAccrualService->listForActor($request->user(), (int) ($request->input('per_page', 15)));

        return $this->successResponse(DomainConstants::MSG_PRM_COMMISSION_FETCHED, [
            'items' => collect($items->items())->map(fn ($r) => [
                'id' => $r->id,
                'partner_organization_id' => $r->partner_organization_id,
                'base_amount' => $r->base_amount,
                'commission_amount' => $r->commission_amount,
                'currency_code' => $r->currency_code,
                'status' => $r->status,
                'quote_id' => $r->quote_id,
                'payment_record_id' => $r->payment_record_id,
                'created_at' => $r->created_at?->toIso8601String(),
            ]),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function updateStatus(Request $request, int $accrualId): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'string']]);
        $row = $this->commissionAccrualService->updateStatus(
            $request->user(),
            $accrualId,
            (string) $data['status'],
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_PRM_COMMISSION_UPDATED, [
            'accrual' => [
                'id' => $row->id,
                'status' => $row->status,
                'approved_at' => $row->approved_at?->toIso8601String(),
                'paid_at' => $row->paid_at?->toIso8601String(),
            ],
        ]);
    }
}
