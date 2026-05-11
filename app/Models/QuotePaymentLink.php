<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotePaymentLink extends Model
{
    public const STATUS_CREATED = 'created';
    public const STATUS_SENT = 'sent';
    public const STATUS_OPENED = 'opened';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'tenant_id',
        'quote_id',
        'last_payment_record_id',
        'token',
        'status',
        'recipient_email',
        'sent_at',
        'expires_at',
        'last_opened_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
