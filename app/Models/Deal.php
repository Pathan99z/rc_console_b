<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deal extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_OPEN = 0;

    public const STATUS_WON = 1;

    public const STATUS_LOST = 2;

    protected $fillable = [
        'tenant_id',
        'partner_organization_id',
        'partner_registered_by_user_id',
        'partner_opportunity_fingerprint',
        'contact_id',
        'company_id',
        'owner_user_id',
        'pipeline_id',
        'pipeline_stage_id',
        'last_quote_id',
        'created_by_user_id',
        'updated_by_user_id',
        'name',
        'estimated_value',
        'currency_code',
        'probability',
        'expected_close_date',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
            'expected_close_date' => 'date',
            'status' => 'integer',
            'meta' => 'array',
            'currency_code' => 'string',
            'probability' => 'integer',
        ];
    }

    public function partnerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'partner_organization_id');
    }

    public function partnerRegisteredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_registered_by_user_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(DealHistory::class)->latest('id');
    }

    public function lastQuote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'last_quote_id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_WON => 'won',
            self::STATUS_LOST => 'lost',
            default => 'open',
        };
    }

    public static function statusCodeFromString(string $status): int
    {
        return match ($status) {
            'won' => self::STATUS_WON,
            'lost' => self::STATUS_LOST,
            default => self::STATUS_OPEN,
        };
    }
}
