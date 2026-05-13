<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionAccrual extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'partner_organization_id',
        'partner_program_enrollment_id',
        'payment_record_id',
        'quote_id',
        'base_amount',
        'commission_amount',
        'currency_code',
        'calculation_type',
        'status',
        'approved_at',
        'paid_at',
        'rule_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'base_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'rule_snapshot' => 'array',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function partnerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'partner_organization_id');
    }

    public function paymentRecord(): BelongsTo
    {
        return $this->belongsTo(PaymentRecord::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(PartnerProgramEnrollment::class, 'partner_program_enrollment_id');
    }
}
