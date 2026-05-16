<?php

namespace App\Http\Controllers\Api\Prm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CommissionAccrual;
use App\Models\Payout;
use App\Models\PayoutLineItem;
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
            'items' => collect($items->items())->map(fn (CommissionAccrual $r) => $this->accrualItem($r)),
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

    /**
     * @return array<string, mixed>
     */
    private function accrualItem(CommissionAccrual $r): array
    {
        $org = $r->partnerOrganization;
        $quote = $r->quote;
        $payment = $r->paymentRecord;
        $line = $r->payoutLineItem;
        $payout = $line?->payout;
        $lockedInPayout = $this->isLockedInPayout($line, $payout);
        $orgName = $org?->display_name ?? $org?->legal_name;
        $quoteNumber = $quote?->quote_number;
        $commissionAmount = (float) $r->commission_amount;

        $summaryParts = array_filter([
            $orgName,
            $quoteNumber ? "Quote {$quoteNumber}" : null,
            number_format($commissionAmount, 2).' '.($r->currency_code ?? 'ZAR'),
        ]);

        return [
            'id' => $r->id,
            'partner_organization_id' => $r->partner_organization_id,
            'base_amount' => (float) $r->base_amount,
            'commission_amount' => $commissionAmount,
            'amount' => $commissionAmount,
            'currency_code' => $r->currency_code,
            'calculation_type' => $r->calculation_type,
            'status' => $r->status,
            'quote_id' => $r->quote_id,
            'payment_record_id' => $r->payment_record_id,
            'approved_at' => $r->approved_at?->toIso8601String(),
            'paid_at' => $r->paid_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
            'summary' => $summaryParts !== [] ? implode(' · ', $summaryParts) : null,
            'partner_organization' => $org ? [
                'id' => $org->id,
                'type' => $org->type,
                'display_name' => $org->display_name,
                'legal_name' => $org->legal_name,
                'name' => $orgName,
            ] : null,
            'quote' => $quote ? [
                'id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'total' => (float) $quote->total,
                'currency_code' => $quote->currency_code,
                'status' => $quote->status,
                'status_label' => $quote->statusLabel(),
                'payment_status' => $quote->payment_status,
                'payment_status_label' => $quote->paymentStatusLabel(),
                'deal_id' => $quote->deal_id,
                'deal_name' => $quote->deal?->name,
            ] : null,
            'payment_record' => $payment ? [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'currency_code' => $payment->currency_code,
                'status' => $payment->status,
                'transaction_id' => $payment->transaction_id,
            ] : null,
            'in_payout' => $lockedInPayout,
            'available_for_payout' => $r->status === CommissionAccrual::STATUS_APPROVED && ! $lockedInPayout,
            'payout' => $lockedInPayout && $payout ? [
                'id' => $payout->id,
                'payout_number' => $payout->payout_number,
                'status' => $payout->status,
            ] : null,
        ];
    }

    private function isLockedInPayout(?PayoutLineItem $line, ?Payout $payout): bool
    {
        if (! $line || ! $payout) {
            return false;
        }

        return ! in_array($payout->status, [
            Payout::STATUS_CANCELLED,
            Payout::STATUS_FAILED,
            Payout::STATUS_REVERSED,
        ], true);
    }
}
