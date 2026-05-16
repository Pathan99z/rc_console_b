<?php

namespace App\Http\Controllers\Api\Prm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Payout;
use App\Services\Prm\PayoutGenerationService;
use App\Services\Prm\PayoutReconciliationService;
use App\Services\Prm\PayoutStatementService;
use App\Services\Prm\PayoutWorkflowService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PayoutController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PayoutGenerationService $generationService,
        private readonly PayoutWorkflowService $workflowService,
        private readonly PayoutStatementService $statementService,
        private readonly PayoutReconciliationService $reconciliationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->generationService->listForActor($request->user(), $request->query(), (int) $request->input('per_page', 15));

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_FETCHED, [
            'items' => collect($items->items())->map(fn (Payout $p) => $this->payoutSummary($p)),
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
            'payout' => $this->payoutDetail($payout, $request->user()->isCompanyAdmin() || $request->user()->isFinanceAdmin() || $request->user()->isGlobalAdmin()),
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'beneficiary_organization_id' => ['nullable', 'integer'],
            'accrual_ids' => ['nullable', 'array'],
            'accrual_ids.*' => ['integer'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $created = $this->generationService->generate($request->user(), $data, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_GENERATED, [
            'payouts' => collect($created)->map(fn (Payout $p) => $this->payoutSummary($p)),
        ], 201);
    }

    public function submit(Request $request, int $payoutId): JsonResponse
    {
        $payout = $this->workflowService->submit($request->user(), $payoutId, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_UPDATED, ['payout' => $this->payoutSummary($payout)]);
    }

    public function approve(Request $request, int $payoutId): JsonResponse
    {
        $payout = $this->workflowService->approve($request->user(), $payoutId, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_UPDATED, ['payout' => $this->payoutSummary($payout)]);
    }

    public function reject(Request $request, int $payoutId): JsonResponse
    {
        $data = $request->validate(['remarks' => ['nullable', 'string']]);
        $payout = $this->workflowService->reject($request->user(), $payoutId, $data['remarks'] ?? null, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_UPDATED, ['payout' => $this->payoutSummary($payout)]);
    }

    public function process(Request $request, int $payoutId): JsonResponse
    {
        $payout = $this->workflowService->process($request->user(), $payoutId, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_UPDATED, ['payout' => $this->payoutSummary($payout)]);
    }

    public function markPaid(Request $request, int $payoutId): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['required', 'string'],
            'remittance_reference' => ['required', 'string', 'max:128'],
            'payment_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string'],
            'supporting_document' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ]);

        $payout = $this->workflowService->markPaid(
            $request->user(),
            $payoutId,
            $data,
            $request->file('supporting_document'),
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_PAID, ['payout' => $this->payoutSummary($payout)]);
    }

    public function fail(Request $request, int $payoutId): JsonResponse
    {
        $data = $request->validate([
            'failure_reason' => ['required', 'string'],
            'remarks' => ['nullable', 'string'],
        ]);

        $payout = $this->workflowService->fail(
            $request->user(),
            $payoutId,
            $data['failure_reason'],
            $data['remarks'] ?? null,
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_UPDATED, ['payout' => $this->payoutSummary($payout)]);
    }

    public function reverse(Request $request, int $payoutId): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string'],
            'reference' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
        ]);

        $payout = $this->workflowService->reverse($request->user(), $payoutId, $data, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_REVERSED, ['payout' => $this->payoutSummary($payout)]);
    }

    public function statement(Request $request, int $payoutId): JsonResponse
    {
        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_STATEMENT, [
            'statement' => $this->statementService->build($request->user(), $payoutId),
        ]);
    }

    public function export(Request $request)
    {
        return $this->statementService->exportCsv($request->user(), $request->query());
    }

    public function reconciliation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_RECONCILIATION, [
            'reconciliation' => $this->reconciliationService->summary(
                $request->user(),
                $data['from'] ?? null,
                $data['to'] ?? null
            ),
        ]);
    }

    public function proof(Request $request, int $payoutId)
    {
        $payout = $this->generationService->findForActor($request->user(), $payoutId);
        if (! $payout->supporting_document_path || ! Storage::disk('local')->exists($payout->supporting_document_path)) {
            return $this->errorResponse('Payment proof not found.', null, 404);
        }

        return Storage::disk('local')->download($payout->supporting_document_path);
    }

    /**
     * @return array<string, mixed>
     */
    private function payoutSummary(Payout $p): array
    {
        return [
            'id' => $p->id,
            'payout_number' => $p->payout_number,
            'beneficiary_organization_id' => $p->beneficiary_organization_id,
            'status' => $p->status,
            'currency_code' => $p->currency_code,
            'gross_amount' => (float) $p->gross_amount,
            'net_amount' => (float) $p->net_amount,
            'paid_at' => $p->paid_at?->toIso8601String(),
            'remittance_reference' => $p->remittance_reference,
            'has_payment_proof' => $p->supporting_document_path !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payoutDetail(Payout $p, bool $includeProofPath): array
    {
        $summary = $this->payoutSummary($p);
        $summary['adjustment_amount'] = (float) $p->adjustment_amount;
        $summary['tax_amount'] = (float) $p->tax_amount;
        $summary['payment_method'] = $p->payment_method;
        $summary['remarks'] = $p->remarks;
        $summary['failure_reason'] = $p->failure_reason;
        $summary['period_from'] = $p->period_from?->format('Y-m-d');
        $summary['period_to'] = $p->period_to?->format('Y-m-d');
        $summary['line_items'] = $p->lineItems->map(fn ($line) => [
            'id' => $line->id,
            'commission_accrual_id' => $line->commission_accrual_id,
            'amount' => (float) $line->amount,
        ]);
        if ($includeProofPath && $p->supporting_document_path) {
            $summary['payment_proof_url'] = url("/api/prm/payouts/{$p->id}/proof");
        }

        return $summary;
    }
}
