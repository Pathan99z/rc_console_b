<?php

namespace App\Services\Prm;

use App\Models\CommissionAccrual;
use App\Models\PartnerProgramEnrollment;
use App\Models\PaymentRecord;
use App\Models\Quote;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Services\Auth\AccessScopeService;
use App\Support\Prm\CommissionAccrualTransition;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class CommissionAccrualService
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly AccessScopeService $accessScopeService,
        private readonly CommissionResolutionService $commissionResolutionService,
    ) {}

    public function processSuccessfulPayment(Quote $quote, PaymentRecord $record): void
    {
        $quote->loadMissing('deal');
        $deal = $quote->deal;
        if (! $deal) {
            return;
        }

        $resolved = $this->commissionResolutionService->resolveForDeal($deal);
        $beneficiaryOrgId = (int) ($resolved['beneficiary_organization_id'] ?? 0);
        $percent = (float) ($resolved['commission_percent'] ?? 0);
        $enrollment = $resolved['enrollment'] ?? null;

        if ($beneficiaryOrgId <= 0 || $percent <= 0) {
            return;
        }

        if ($record->id && CommissionAccrual::query()
            ->where('payment_record_id', $record->id)
            ->where('status', '!=', CommissionAccrual::STATUS_VOID)
            ->exists()) {
            return;
        }

        $base = (float) $record->amount;
        $commission = round($base * ($percent / 100.0), 2);

        CommissionAccrual::query()->create([
            'tenant_id' => $quote->tenant_id,
            'partner_organization_id' => $beneficiaryOrgId,
            'partner_program_enrollment_id' => $enrollment?->id,
            'payment_record_id' => $record->id,
            'quote_id' => $quote->id,
            'base_amount' => $base,
            'commission_amount' => $commission,
            'currency_code' => $record->currency_code ?? 'ZAR',
            'calculation_type' => 'percentage',
            'status' => CommissionAccrual::STATUS_PENDING,
            'rule_snapshot' => [
                'percent' => $percent,
                'enrollment_id' => $enrollment?->id,
                'resolution_mode' => $resolved['resolution_mode'],
                'channel_organization_id' => $deal->channel_organization_id ?? $deal->partner_organization_id,
            ],
        ]);
    }

    public function listForActor(User $actor, int $perPage): LengthAwarePaginator
    {
        $q = CommissionAccrual::query()
            ->with([
                'partnerOrganization:id,tenant_id,type,display_name,legal_name',
                'quote' => fn ($query) => $query->select([
                    'id',
                    'tenant_id',
                    'deal_id',
                    'quote_number',
                    'status',
                    'payment_status',
                    'total',
                    'currency_code',
                ])->with('deal:id,name'),
                'paymentRecord:id,quote_id,amount,currency_code,status,transaction_id',
                'payoutLineItem.payout:id,status,payout_number',
            ])
            ->orderByDesc('id');
        if ($actor->isGlobalAdmin()) {
            return $q->paginate($perPage);
        }
        if ($actor->isCompanyAdmin()) {
            return $q->where('tenant_id', $actor->tenant_id)->paginate($perPage);
        }
        if ($actor->isPartnerPortalEligible()) {
            $orgIds = $this->accessScopeService->visibleChannelOrgIds($actor);
            if ($orgIds === []) {
                return $q->whereRaw('1 = 0')->paginate($perPage);
            }

            return $q->where('tenant_id', $actor->tenant_id)
                ->whereIn('partner_organization_id', $orgIds)
                ->paginate($perPage);
        }

        throw ValidationException::withMessages([
            'organization' => ['Not allowed to list commissions.'],
        ]);
    }

    public function updateStatus(User $actor, int $accrualId, string $status, ?string $ip, ?string $ua): CommissionAccrual
    {
        if (! $actor->isCompanyAdmin() && ! $actor->isGlobalAdmin() && ! $actor->isFinanceAdmin()) {
            throw ValidationException::withMessages([
                'organization' => ['Only finance administrators can update commission status.'],
            ]);
        }

        $accrual = CommissionAccrual::query()->whereKey($accrualId)->first();
        if (! $accrual || ((int) $accrual->tenant_id !== (int) $actor->tenant_id && ! $actor->isGlobalAdmin())) {
            throw new ModelNotFoundException('Commission accrual not found.');
        }

        if (! in_array($status, [
            CommissionAccrual::STATUS_PENDING,
            CommissionAccrual::STATUS_APPROVED,
            CommissionAccrual::STATUS_PAID,
            CommissionAccrual::STATUS_VOID,
        ], true)) {
            throw ValidationException::withMessages(['status' => ['Invalid status.']]);
        }

        CommissionAccrualTransition::assertCanTransition($accrual->status, $status);

        $before = $accrual->toArray();
        $updates = ['status' => $status];
        if ($status === CommissionAccrual::STATUS_APPROVED) {
            $updates['approved_at'] = now();
        }
        if ($status === CommissionAccrual::STATUS_PAID) {
            $updates['paid_at'] = now();
        }
        $accrual->update($updates);
        $fresh = $accrual->refresh();
        $this->auditLogRepository->create([
            'tenant_id' => $fresh->tenant_id,
            'user_id' => $actor->id,
            'module' => 'prm',
            'action' => 'prm.commission.status',
            'entity_type' => 'commission_accrual',
            'entity_id' => $fresh->id,
            'before' => $before,
            'after' => $fresh->toArray(),
            'ip_address' => $ip,
            'user_agent' => $ua,
        ]);

        return $fresh;
    }
}
