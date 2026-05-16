<?php

namespace App\Support\Prm;

use App\Models\CommissionAccrual;
use Illuminate\Validation\ValidationException;

class CommissionAccrualTransition
{
    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        CommissionAccrual::STATUS_PENDING => [
            CommissionAccrual::STATUS_APPROVED,
            CommissionAccrual::STATUS_VOID,
        ],
        CommissionAccrual::STATUS_APPROVED => [
            CommissionAccrual::STATUS_PENDING,
            CommissionAccrual::STATUS_PAID,
            CommissionAccrual::STATUS_VOID,
        ],
        CommissionAccrual::STATUS_PAID => [
            CommissionAccrual::STATUS_VOID,
        ],
        CommissionAccrual::STATUS_VOID => [],
    ];

    public static function assertCanTransition(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $allowed = self::ALLOWED[$from] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition commission accrual from {$from} to {$to}."],
            ]);
        }
    }
}
