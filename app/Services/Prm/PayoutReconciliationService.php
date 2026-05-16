<?php

namespace App\Services\Prm;

use App\Models\CommissionAccrual;
use App\Models\PaymentRecord;
use App\Models\Payout;
use App\Models\User;
use App\Support\Prm\PayoutAccessScope;

class PayoutReconciliationService
{
    public function __construct(private readonly PayoutAccessScope $accessScope) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(User $actor, ?string $from = null, ?string $to = null): array
    {
        $this->accessScope->assertCanManageFinance($actor);
        $tenantId = (int) $actor->tenant_id;

        $paymentsQ = PaymentRecord::query()
            ->where('tenant_id', $tenantId)
            ->where('status', PaymentRecord::STATUS_SUCCESS);
        $accrualsQ = CommissionAccrual::query()->where('tenant_id', $tenantId);
        $payoutsQ = Payout::query()->where('tenant_id', $tenantId);

        if ($from) {
            $paymentsQ->whereDate('created_at', '>=', $from);
            $accrualsQ->whereDate('created_at', '>=', $from);
            $payoutsQ->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $paymentsQ->whereDate('created_at', '<=', $to);
            $accrualsQ->whereDate('created_at', '<=', $to);
            $payoutsQ->whereDate('created_at', '<=', $to);
        }

        $cashIn = round((float) $paymentsQ->sum('amount'), 2);
        $accrued = round((float) (clone $accrualsQ)->where('status', '!=', CommissionAccrual::STATUS_VOID)->sum('commission_amount'), 2);
        $paidOut = round((float) (clone $payoutsQ)->where('status', Payout::STATUS_PAID)->sum('net_amount'), 2);
        $liability = round((float) (clone $accrualsQ)->where('status', CommissionAccrual::STATUS_APPROVED)->sum('commission_amount'), 2);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'customer_cash_in' => $cashIn,
            'commission_accrued' => $accrued,
            'commission_liability_approved' => $liability,
            'payouts_paid_out' => $paidOut,
            'variance_cash_minus_accrued' => round($cashIn - $accrued, 2),
            'variance_accrued_minus_paid' => round($accrued - $paidOut, 2),
        ];
    }
}
