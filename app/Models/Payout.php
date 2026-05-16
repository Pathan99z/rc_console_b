<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payout extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVERSED = 'reversed';

    public const STATUS_CANCELLED = 'cancelled';

    public const PAYMENT_METHOD_IMPS = 'imps';

    public const PAYMENT_METHOD_NEFT = 'neft';

    public const PAYMENT_METHOD_RTGS = 'rtgs';

    public const PAYMENT_METHOD_SWIFT = 'swift';

    public const PAYMENT_METHOD_CHEQUE = 'cheque';

    public const PAYMENT_METHOD_CASH = 'cash';

    public const PAYMENT_METHOD_MANUAL_TRANSFER = 'manual_transfer';

    public const PAYMENT_METHOD_OTHER = 'other';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'beneficiary_organization_id',
        'payout_number',
        'status',
        'currency_code',
        'gross_amount',
        'adjustment_amount',
        'tax_amount',
        'net_amount',
        'period_from',
        'period_to',
        'approved_by_user_id',
        'approved_at',
        'processed_by_user_id',
        'processed_at',
        'paid_by_user_id',
        'paid_at',
        'payment_method',
        'remittance_reference',
        'remarks',
        'failure_reason',
        'supporting_document_path',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'adjustment_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'period_from' => 'date',
            'period_to' => 'date',
            'approved_at' => 'datetime',
            'processed_at' => 'datetime',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function beneficiaryOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'beneficiary_organization_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(PayoutLineItem::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PayoutAdjustment::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(PayoutDispute::class);
    }

    /**
     * @return list<string>
     */
    public static function paymentMethods(): array
    {
        return [
            self::PAYMENT_METHOD_IMPS,
            self::PAYMENT_METHOD_NEFT,
            self::PAYMENT_METHOD_RTGS,
            self::PAYMENT_METHOD_SWIFT,
            self::PAYMENT_METHOD_CHEQUE,
            self::PAYMENT_METHOD_CASH,
            self::PAYMENT_METHOD_MANUAL_TRANSFER,
            self::PAYMENT_METHOD_OTHER,
        ];
    }
}
