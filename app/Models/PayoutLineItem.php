<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutLineItem extends Model
{
    protected $fillable = [
        'tenant_id',
        'payout_id',
        'commission_accrual_id',
        'amount',
        'currency_code',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function commissionAccrual(): BelongsTo
    {
        return $this->belongsTo(CommissionAccrual::class);
    }
}
