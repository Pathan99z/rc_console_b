<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 0;
    public const STATUS_SENT = 1;
    public const STATUS_ACCEPTED = 2;
    public const STATUS_REJECTED = 3;

    public const PAYMENT_STATUS_UNPAID = 0;

    public const PAYMENT_STATUS_PAID = 1;

    protected $fillable = [
        'tenant_id',
        'deal_id',
        'contact_id',
        'created_by_user_id',
        'updated_by_user_id',
        'quote_number',
        'public_uuid',
        'status',
        'payment_status',
        'quote_type',
        'notes',
        'valid_until',
        'subtotal',
        'tax_total',
        'discount_total',
        'total',
        'currency_code',
        'pdf_file_key',
        'last_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'payment_status' => 'integer',
            'quote_type' => 'integer',
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'total' => 'decimal:2',
            'last_sent_at' => 'datetime',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('sort_order');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(QuoteAttachment::class)->orderByDesc('id');
    }

    public function paymentLinks(): HasMany
    {
        return $this->hasMany(QuotePaymentLink::class)->orderByDesc('id');
    }

    public function statusLabel(): string
    {
        return match ((int) $this->status) {
            self::STATUS_SENT => 'sent',
            self::STATUS_ACCEPTED => 'accepted',
            self::STATUS_REJECTED => 'rejected',
            default => 'draft',
        };
    }

    public function paymentStatusLabel(): string
    {
        return (int) $this->payment_status === self::PAYMENT_STATUS_PAID ? 'paid' : 'unpaid';
    }

    public static function statusCodeFromString(string $status): int
    {
        return match ($status) {
            'sent' => self::STATUS_SENT,
            'accepted' => self::STATUS_ACCEPTED,
            'rejected' => self::STATUS_REJECTED,
            default => self::STATUS_DRAFT,
        };
    }
}
