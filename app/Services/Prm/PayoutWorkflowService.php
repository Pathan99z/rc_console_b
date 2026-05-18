<?php

namespace App\Services\Prm;

use App\Models\CommissionAccrual;
use App\Models\Payout;
use App\Models\PayoutAdjustment;
use App\Models\PayoutLineItem;
use App\Models\User;
use App\Support\Prm\CommissionAccrualTransition;
use App\Support\Prm\PayoutAccessScope;
use Illuminate\Http\UploadedFile;
use App\Support\Storage\EnterpriseStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayoutWorkflowService
{
    public function __construct(
        private readonly PayoutAccessScope $accessScope,
        private readonly PayoutAuditLogger $auditLogger,
        private readonly PayoutGenerationService $generationService,
        private readonly EnterpriseStorage $storage,
    ) {}

    public function submit(User $actor, int $payoutId, ?string $ip = null, ?string $ua = null): Payout
    {
        return $this->transition($actor, $payoutId, Payout::STATUS_DRAFT, Payout::STATUS_SUBMITTED, 'prm.payout.submit', $ip, $ua);
    }

    public function approve(User $actor, int $payoutId, ?string $ip = null, ?string $ua = null): Payout
    {
        return DB::transaction(function () use ($actor, $payoutId, $ip, $ua): Payout {
            $payout = $this->generationService->findForActor($actor, $payoutId);
            $this->accessScope->assertCanManageFinance($actor);
            $before = $payout->toArray();
            $this->assertStatus($payout, Payout::STATUS_SUBMITTED);

            $payout->update([
                'status' => Payout::STATUS_APPROVED,
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
            ]);

            $fresh = $payout->refresh();
            $this->auditLogger->log($actor, 'prm.payout.approve', $fresh, $before, $fresh->toArray(), $ip, $ua);

            return $fresh;
        });
    }

    public function reject(User $actor, int $payoutId, ?string $remarks = null, ?string $ip = null, ?string $ua = null): Payout
    {
        return DB::transaction(function () use ($actor, $payoutId, $remarks, $ip, $ua): Payout {
            $payout = $this->generationService->findForActor($actor, $payoutId);
            $this->accessScope->assertCanManageFinance($actor);
            $before = $payout->toArray();

            if (! in_array($payout->status, [Payout::STATUS_SUBMITTED, Payout::STATUS_APPROVED, Payout::STATUS_DRAFT], true)) {
                throw ValidationException::withMessages(['status' => ['Payout cannot be rejected in its current state.']]);
            }

            $this->releaseLineItems($payout);
            $payout->update([
                'status' => Payout::STATUS_CANCELLED,
                'remarks' => $remarks ?? $payout->remarks,
            ]);

            $fresh = $payout->refresh();
            $this->auditLogger->log($actor, 'prm.payout.reject', $fresh, $before, $fresh->toArray(), $ip, $ua);

            return $fresh;
        });
    }

    public function process(User $actor, int $payoutId, ?string $ip = null, ?string $ua = null): Payout
    {
        return DB::transaction(function () use ($actor, $payoutId, $ip, $ua): Payout {
            $payout = $this->generationService->findForActor($actor, $payoutId);
            $this->accessScope->assertCanManageFinance($actor);
            $before = $payout->toArray();
            $this->assertStatus($payout, Payout::STATUS_APPROVED);

            $payout->update([
                'status' => Payout::STATUS_PROCESSING,
                'processed_by_user_id' => $actor->id,
                'processed_at' => now(),
            ]);

            $fresh = $payout->refresh();
            $this->auditLogger->log($actor, 'prm.payout.process', $fresh, $before, $fresh->toArray(), $ip, $ua);

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function markPaid(User $actor, int $payoutId, array $data, ?UploadedFile $proof = null, ?string $ip = null, ?string $ua = null): Payout
    {
        return DB::transaction(function () use ($actor, $payoutId, $data, $proof, $ip, $ua): Payout {
            $payout = $this->generationService->findForActor($actor, $payoutId);
            $this->accessScope->assertCanManageFinance($actor);
            $before = $payout->toArray();
            $this->assertStatus($payout, Payout::STATUS_PROCESSING);

            $method = (string) ($data['payment_method'] ?? '');
            if (! in_array($method, Payout::paymentMethods(), true)) {
                throw ValidationException::withMessages(['payment_method' => ['Invalid payment method.']]);
            }

            $path = $payout->supporting_document_path;
            if ($proof) {
                $path = $this->storage->storeUploadedFile(
                    $proof,
                    "tenant/{$payout->tenant_id}/payouts/{$payout->id}"
                );
            }

            $paidAt = ! empty($data['payment_date']) ? $data['payment_date'] : now();

            $payout->update([
                'status' => Payout::STATUS_PAID,
                'payment_method' => $method,
                'remittance_reference' => (string) ($data['remittance_reference'] ?? ''),
                'remarks' => $data['remarks'] ?? $payout->remarks,
                'paid_by_user_id' => $actor->id,
                'paid_at' => $paidAt,
                'supporting_document_path' => $path,
            ]);

            foreach ($payout->lineItems as $line) {
                $accrual = $line->commissionAccrual;
                if ($accrual && $accrual->status === CommissionAccrual::STATUS_APPROVED) {
                    CommissionAccrualTransition::assertCanTransition($accrual->status, CommissionAccrual::STATUS_PAID);
                    $accrual->update([
                        'status' => CommissionAccrual::STATUS_PAID,
                        'paid_at' => $paidAt,
                    ]);
                }
            }

            $fresh = $payout->refresh();
            $this->auditLogger->log($actor, 'prm.payout.mark_paid', $fresh, $before, $fresh->toArray(), $ip, $ua);

            return $fresh;
        });
    }

    public function fail(User $actor, int $payoutId, ?string $failureReason = null, ?string $remarks = null, ?string $ip = null, ?string $ua = null): Payout
    {
        return DB::transaction(function () use ($actor, $payoutId, $failureReason, $remarks, $ip, $ua): Payout {
            $payout = $this->generationService->findForActor($actor, $payoutId);
            $this->accessScope->assertCanManageFinance($actor);
            $before = $payout->toArray();

            if (! in_array($payout->status, [Payout::STATUS_PROCESSING, Payout::STATUS_APPROVED], true)) {
                throw ValidationException::withMessages(['status' => ['Payout cannot be marked failed in its current state.']]);
            }

            $this->releaseLineItems($payout);
            $payout->update([
                'status' => Payout::STATUS_FAILED,
                'failure_reason' => $failureReason,
                'remarks' => $remarks ?? $payout->remarks,
            ]);

            $fresh = $payout->refresh();
            $this->auditLogger->log($actor, 'prm.payout.fail', $fresh, $before, $fresh->toArray(), $ip, $ua);

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reverse(User $actor, int $payoutId, array $data, ?string $ip = null, ?string $ua = null): Payout
    {
        return DB::transaction(function () use ($actor, $payoutId, $data, $ip, $ua): Payout {
            $payout = $this->generationService->findForActor($actor, $payoutId);
            $this->accessScope->assertCanManageFinance($actor);
            $before = $payout->toArray();
            $this->assertStatus($payout, Payout::STATUS_PAID);

            PayoutAdjustment::query()->create([
                'tenant_id' => $payout->tenant_id,
                'organization_id' => $payout->beneficiary_organization_id,
                'payout_id' => $payout->id,
                'type' => PayoutAdjustment::TYPE_DEBIT,
                'amount' => $payout->net_amount,
                'currency_code' => $payout->currency_code,
                'reason' => (string) ($data['reason'] ?? 'Payout reversal clawback'),
                'remarks' => $data['remarks'] ?? null,
                'created_by_user_id' => $actor->id,
            ]);

            $payout->update([
                'status' => Payout::STATUS_REVERSED,
                'remarks' => $data['remarks'] ?? $payout->remarks,
                'metadata' => array_merge($payout->metadata ?? [], [
                    'reversal_reference' => $data['reference'] ?? null,
                    'reversed_by' => $actor->id,
                    'reversed_at' => now()->toIso8601String(),
                ]),
            ]);

            $fresh = $payout->refresh();
            $this->auditLogger->log($actor, 'prm.payout.reverse', $fresh, $before, $fresh->toArray(), $ip, $ua);

            return $fresh;
        });
    }

    public function proofUrl(Payout $payout): ?string
    {
        if (! $payout->supporting_document_path) {
            return null;
        }

        return route('api.prm.payouts.proof', ['payoutId' => $payout->id], false);
    }

    private function transition(User $actor, int $payoutId, string $from, string $to, string $action, ?string $ip, ?string $ua): Payout
    {
        return DB::transaction(function () use ($actor, $payoutId, $from, $to, $action, $ip, $ua): Payout {
            $payout = $this->generationService->findForActor($actor, $payoutId);
            $this->accessScope->assertCanManageFinance($actor);
            $before = $payout->toArray();
            $this->assertStatus($payout, $from);
            $payout->update(['status' => $to]);
            $fresh = $payout->refresh();
            $this->auditLogger->log($actor, $action, $fresh, $before, $fresh->toArray(), $ip, $ua);

            return $fresh;
        });
    }

    private function assertStatus(Payout $payout, string $expected): void
    {
        if ($payout->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => ["Payout must be in {$expected} status. Current: {$payout->status}."],
            ]);
        }
    }

    private function releaseLineItems(Payout $payout): void
    {
        PayoutLineItem::query()->where('payout_id', $payout->id)->delete();
    }
}
