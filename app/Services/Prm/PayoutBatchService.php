<?php

namespace App\Services\Prm;

use App\Models\Payout;
use App\Models\PayoutBatch;
use App\Models\PayoutBatchItem;
use App\Models\User;
use App\Support\Prm\PayoutAccessScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayoutBatchService
{
    public function __construct(
        private readonly PayoutAccessScope $accessScope,
        private readonly PayoutAuditLogger $auditLogger,
        private readonly PayoutWorkflowService $workflowService,
        private readonly PayoutGenerationService $generationService,
    ) {}

    /**
     * @param  list<int>  $payoutIds
     */
    public function create(User $actor, array $payoutIds, ?string $ip = null, ?string $ua = null): PayoutBatch
    {
        $this->accessScope->assertCanManageFinance($actor);

        return DB::transaction(function () use ($actor, $payoutIds, $ip, $ua): PayoutBatch {
            $tenantId = (int) $actor->tenant_id;
            $payouts = Payout::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $payoutIds)
                ->where('status', Payout::STATUS_APPROVED)
                ->lockForUpdate()
                ->get();

            if ($payouts->count() !== count($payoutIds)) {
                throw ValidationException::withMessages([
                    'payout_ids' => ['All payouts must exist and be in approved status.'],
                ]);
            }

            $batch = PayoutBatch::query()->create([
                'tenant_id' => $tenantId,
                'batch_number' => sprintf('PB-%d-%06d', $tenantId, PayoutBatch::query()->where('tenant_id', $tenantId)->count() + 1),
                'status' => PayoutBatch::STATUS_DRAFT,
                'currency_code' => (string) ($payouts->first()->currency_code ?? 'ZAR'),
                'total_amount' => round((float) $payouts->sum('net_amount'), 2),
                'created_by_user_id' => $actor->id,
            ]);

            foreach ($payouts as $payout) {
                PayoutBatchItem::query()->create([
                    'tenant_id' => $tenantId,
                    'payout_batch_id' => $batch->id,
                    'payout_id' => $payout->id,
                ]);
            }

            $this->auditLogger->log($actor, 'prm.payout_batch.create', $payouts->first(), null, $batch->toArray(), $ip, $ua);

            return $batch->load('items.payout');
        });
    }

    public function find(User $actor, int $batchId): PayoutBatch
    {
        $this->accessScope->assertCanManageFinance($actor);

        $batch = PayoutBatch::query()
            ->with(['items.payout.beneficiaryOrganization'])
            ->whereKey($batchId)
            ->where('tenant_id', $actor->tenant_id)
            ->first();

        if (! $batch) {
            throw ValidationException::withMessages(['batch' => ['Payout batch not found.']]);
        }

        return $batch;
    }

    public function process(User $actor, int $batchId, ?string $ip = null, ?string $ua = null): PayoutBatch
    {
        return DB::transaction(function () use ($actor, $batchId, $ip, $ua): PayoutBatch {
            $batch = $this->find($actor, $batchId);
            $batch->update([
                'status' => PayoutBatch::STATUS_PROCESSING,
                'processed_by_user_id' => $actor->id,
                'processed_at' => now(),
            ]);

            foreach ($batch->items as $item) {
                if ($item->payout && $item->payout->status === Payout::STATUS_APPROVED) {
                    $this->workflowService->process($actor, (int) $item->payout_id, $ip, $ua);
                }
            }

            return $batch->refresh()->load('items.payout');
        });
    }

    /**
     * @param  array<string, mixed>  $paymentData
     */
    public function markPaid(User $actor, int $batchId, array $paymentData, ?string $ip = null, ?string $ua = null): PayoutBatch
    {
        return DB::transaction(function () use ($actor, $batchId, $paymentData, $ip, $ua): PayoutBatch {
            $batch = $this->find($actor, $batchId);

            foreach ($batch->items as $item) {
                if ($item->payout && $item->payout->status === Payout::STATUS_PROCESSING) {
                    $this->workflowService->markPaid($actor, (int) $item->payout_id, $paymentData, null, $ip, $ua);
                }
            }

            $batch->update(['status' => PayoutBatch::STATUS_PAID]);

            return $batch->refresh()->load('items.payout');
        });
    }
}
