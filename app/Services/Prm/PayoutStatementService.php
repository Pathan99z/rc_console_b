<?php

namespace App\Services\Prm;

use App\Models\Payout;
use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayoutStatementService
{
    public function __construct(private readonly PayoutGenerationService $generationService) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $actor, int $payoutId): array
    {
        $payout = $this->generationService->findForActor($actor, $payoutId);
        $payout->load(['lineItems.commissionAccrual.quote', 'beneficiaryOrganization', 'adjustments']);

        $lines = $payout->lineItems->map(fn ($line) => [
            'commission_accrual_id' => $line->commission_accrual_id,
            'amount' => (float) $line->amount,
            'currency_code' => $line->currency_code,
            'quote_id' => $line->commissionAccrual?->quote_id,
            'payment_record_id' => $line->commissionAccrual?->payment_record_id,
            'base_amount' => $line->commissionAccrual ? (float) $line->commissionAccrual->base_amount : null,
        ])->values()->all();

        return [
            'payout' => [
                'id' => $payout->id,
                'payout_number' => $payout->payout_number,
                'status' => $payout->status,
                'currency_code' => $payout->currency_code,
                'gross_amount' => (float) $payout->gross_amount,
                'adjustment_amount' => (float) $payout->adjustment_amount,
                'tax_amount' => (float) $payout->tax_amount,
                'net_amount' => (float) $payout->net_amount,
                'payment_method' => $payout->payment_method,
                'remittance_reference' => $payout->remittance_reference,
                'paid_at' => $payout->paid_at?->toIso8601String(),
                'period_from' => $payout->period_from?->format('Y-m-d'),
                'period_to' => $payout->period_to?->format('Y-m-d'),
            ],
            'beneficiary' => [
                'organization_id' => $payout->beneficiary_organization_id,
                'display_name' => $payout->beneficiaryOrganization?->display_name,
                'legal_name' => $payout->beneficiaryOrganization?->legal_name,
            ],
            'line_items' => $lines,
            'adjustments' => $payout->adjustments->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'amount' => (float) $a->amount,
                'reason' => $a->reason,
            ])->values()->all(),
            'has_payment_proof' => $payout->supporting_document_path !== null,
        ];
    }

    public function exportCsv(User $actor, array $filters = []): StreamedResponse
    {
        $items = $this->generationService->listForActor($actor, $filters, 5000)->items();

        return response()->streamDownload(function () use ($items): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'payout_number', 'beneficiary_organization_id', 'status', 'gross_amount', 'net_amount', 'currency_code', 'paid_at', 'remittance_reference']);
            foreach ($items as $p) {
                fputcsv($out, [
                    $p->id,
                    $p->payout_number,
                    $p->beneficiary_organization_id,
                    $p->status,
                    $p->gross_amount,
                    $p->net_amount,
                    $p->currency_code,
                    $p->paid_at?->toIso8601String(),
                    $p->remittance_reference,
                ]);
            }
            fclose($out);
        }, 'payouts-export.csv', ['Content-Type' => 'text/csv']);
    }
}
