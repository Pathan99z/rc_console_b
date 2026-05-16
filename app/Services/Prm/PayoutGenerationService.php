<?php

namespace App\Services\Prm;

use App\Models\CommissionAccrual;
use App\Models\Payout;
use App\Models\PayoutLineItem;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Support\Prm\PayoutAccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayoutGenerationService
{
    public function __construct(
        private readonly PayoutAccessScope $accessScope,
        private readonly PayoutAuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return list<Payout>
     */
    public function generate(User $actor, array $input, ?string $ip = null, ?string $ua = null): array
    {
        $this->accessScope->assertCanManageFinance($actor);

        $tenantId = (int) $actor->tenant_id;
        $beneficiaryId = isset($input['beneficiary_organization_id']) ? (int) $input['beneficiary_organization_id'] : null;
        $accrualIds = isset($input['accrual_ids']) && is_array($input['accrual_ids'])
            ? array_map('intval', $input['accrual_ids'])
            : [];

        return DB::transaction(function () use ($actor, $tenantId, $beneficiaryId, $accrualIds, $input, $ip, $ua): array {
            $accruals = $this->eligibleAccrualsQuery($tenantId, $beneficiaryId, $accrualIds, $input)
                ->lockForUpdate()
                ->get();

            if ($accruals->isEmpty()) {
                throw ValidationException::withMessages([
                    'accruals' => ['No approved commission accruals available for payout generation.'],
                ]);
            }

            $grouped = $accruals->groupBy('partner_organization_id');
            $created = [];

            foreach ($grouped as $orgId => $rows) {
                $payout = $this->createPayoutForAccruals($actor, $tenantId, (int) $orgId, $rows, $input);
                $this->auditLogger->log($actor, 'prm.payout.generate', $payout, null, $payout->toArray(), $ip, $ua);
                $created[] = $payout;
            }

            return $created;
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listForActor(User $actor, array $filters, int $perPage): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $q = Payout::query()->with(['beneficiaryOrganization'])->orderByDesc('id');

        if (! $actor->isGlobalAdmin()) {
            $q->where('tenant_id', $actor->tenant_id);
        }

        $visible = $this->accessScope->visibleBeneficiaryOrgIds($actor);
        if ($visible !== []) {
            $q->whereIn('beneficiary_organization_id', $visible);
        } elseif (! $this->accessScope->canManageFinance($actor) && ! $actor->isGlobalAdmin()) {
            return $q->whereRaw('1 = 0')->paginate($perPage);
        }

        if (! empty($filters['status'])) {
            $q->where('status', (string) $filters['status']);
        }
        if (! empty($filters['beneficiary_organization_id'])) {
            $q->where('beneficiary_organization_id', (int) $filters['beneficiary_organization_id']);
        }

        return $q->paginate($perPage);
    }

    public function findForActor(User $actor, int $payoutId): Payout
    {
        $payout = Payout::query()
            ->with(['lineItems.commissionAccrual', 'beneficiaryOrganization', 'adjustments', 'disputes'])
            ->whereKey($payoutId)
            ->first();

        if (! $payout) {
            throw ValidationException::withMessages(['payout' => ['Payout not found.']]);
        }

        $this->accessScope->assertCanViewPayout($actor, $payout);

        return $payout;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function eligibleAccrualsQuery(int $tenantId, ?int $beneficiaryId, array $accrualIds, array $input): Builder
    {
        $q = CommissionAccrual::query()
            ->where('tenant_id', $tenantId)
            ->where('status', CommissionAccrual::STATUS_APPROVED)
            ->whereNotIn('id', function ($sub): void {
                $sub->select('commission_accrual_id')
                    ->from('payout_line_items')
                    ->join('payouts', 'payouts.id', '=', 'payout_line_items.payout_id')
                    ->whereNotIn('payouts.status', [
                        Payout::STATUS_CANCELLED,
                        Payout::STATUS_FAILED,
                        Payout::STATUS_REVERSED,
                    ]);
            });

        if ($beneficiaryId) {
            $q->where('partner_organization_id', $beneficiaryId);
        }

        if ($accrualIds !== []) {
            $q->whereIn('id', $accrualIds);
        }

        if (! empty($input['period_from'])) {
            $q->whereDate('created_at', '>=', $input['period_from']);
        }
        if (! empty($input['period_to'])) {
            $q->whereDate('created_at', '<=', $input['period_to']);
        }

        return $q;
    }

    /**
     * @param  Collection<int, CommissionAccrual>  $accruals
     * @param  array<string, mixed>  $input
     */
    private function createPayoutForAccruals(User $actor, int $tenantId, int $beneficiaryOrgId, Collection $accruals, array $input): Payout
    {
        $gross = round((float) $accruals->sum('commission_amount'), 2);
        $currency = (string) ($accruals->first()->currency_code ?? 'ZAR');

        $payout = Payout::query()->create([
            'tenant_id' => $tenantId,
            'beneficiary_organization_id' => $beneficiaryOrgId,
            'payout_number' => $this->nextPayoutNumber($tenantId),
            'status' => Payout::STATUS_DRAFT,
            'currency_code' => $currency,
            'gross_amount' => $gross,
            'adjustment_amount' => 0,
            'tax_amount' => (float) ($input['tax_amount'] ?? 0),
            'net_amount' => max(0, $gross - (float) ($input['tax_amount'] ?? 0)),
            'period_from' => $input['period_from'] ?? null,
            'period_to' => $input['period_to'] ?? null,
            'metadata' => ['generated_by' => $actor->id],
        ]);

        foreach ($accruals as $accrual) {
            PayoutLineItem::query()->create([
                'tenant_id' => $tenantId,
                'payout_id' => $payout->id,
                'commission_accrual_id' => $accrual->id,
                'amount' => $accrual->commission_amount,
                'currency_code' => $accrual->currency_code,
            ]);
        }

        return $payout->load(['lineItems', 'beneficiaryOrganization']);
    }

    private function nextPayoutNumber(int $tenantId): string
    {
        $seq = Payout::query()->where('tenant_id', $tenantId)->count() + 1;

        return sprintf('PO-%d-%06d', $tenantId, $seq);
    }
}
