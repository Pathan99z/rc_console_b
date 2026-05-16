<?php

namespace App\Services\Prm;

use App\Models\Payout;
use App\Models\PayoutAdjustment;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Support\Prm\PayoutAccessScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayoutAdjustmentService
{
    public function __construct(
        private readonly PayoutAccessScope $accessScope,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly PayoutGenerationService $generationService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data, ?string $ip = null, ?string $ua = null): PayoutAdjustment
    {
        $this->accessScope->assertCanManageFinance($actor);

        $type = (string) ($data['type'] ?? '');
        if (! in_array($type, [PayoutAdjustment::TYPE_CREDIT, PayoutAdjustment::TYPE_DEBIT], true)) {
            throw ValidationException::withMessages(['type' => ['Invalid adjustment type.']]);
        }

        return DB::transaction(function () use ($actor, $data, $type, $ip, $ua): PayoutAdjustment {
            $adjustment = PayoutAdjustment::query()->create([
                'tenant_id' => $actor->tenant_id,
                'organization_id' => (int) $data['organization_id'],
                'payout_id' => isset($data['payout_id']) ? (int) $data['payout_id'] : null,
                'type' => $type,
                'amount' => round((float) $data['amount'], 2),
                'currency_code' => (string) ($data['currency_code'] ?? 'ZAR'),
                'reason' => (string) $data['reason'],
                'remarks' => $data['remarks'] ?? null,
                'created_by_user_id' => $actor->id,
            ]);

            if ($adjustment->payout_id) {
                $payout = Payout::query()->whereKey($adjustment->payout_id)->lockForUpdate()->first();
                if ($payout) {
                    $delta = $type === PayoutAdjustment::TYPE_CREDIT
                        ? (float) $adjustment->amount
                        : -(float) $adjustment->amount;
                    $adjTotal = round((float) $payout->adjustment_amount + $delta, 2);
                    $net = max(0, round((float) $payout->gross_amount + $adjTotal - (float) $payout->tax_amount, 2));
                    $payout->update(['adjustment_amount' => $adjTotal, 'net_amount' => $net]);
                }
            }

            $this->auditLogRepository->create([
                'tenant_id' => $adjustment->tenant_id,
                'user_id' => $actor->id,
                'module' => 'prm.payout',
                'action' => 'prm.payout.adjustment',
                'entity_type' => 'payout_adjustment',
                'entity_id' => $adjustment->id,
                'after' => $adjustment->toArray(),
                'ip_address' => $ip,
                'user_agent' => $ua,
            ]);

            return $adjustment;
        });
    }

    public function listForActor(User $actor, int $perPage): LengthAwarePaginator
    {
        $q = PayoutAdjustment::query()->orderByDesc('id');

        if (! $actor->isGlobalAdmin()) {
            $q->where('tenant_id', $actor->tenant_id);
        }

        $visible = $this->accessScope->visibleBeneficiaryOrgIds($actor);
        if ($visible !== []) {
            $q->whereIn('organization_id', $visible);
        } elseif (! $this->accessScope->canManageFinance($actor) && ! $actor->isGlobalAdmin()) {
            return $q->whereRaw('1 = 0')->paginate($perPage);
        }

        return $q->paginate($perPage);
    }
}
