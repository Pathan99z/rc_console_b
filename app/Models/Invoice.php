<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'tenant_id',
        'quote_id',
        'payment_record_id',
        'invoice_number',
        'status',
        'customer_name',
        'customer_email',
        'subtotal',
        'tax_total',
        'discount_total',
        'total',
        'currency_code',
        'issued_at',
        'paid_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'total' => 'decimal:2',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function paymentRecord(): BelongsTo
    {
        return $this->belongsTo(PaymentRecord::class);
    }
}
