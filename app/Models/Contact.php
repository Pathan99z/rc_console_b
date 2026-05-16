<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use HasFactory, SoftDeletes;

    public const STAGE_LEAD = 0;
    public const STAGE_PROSPECT = 1;
    public const STAGE_CUSTOMER = 2;
    public const STAGE_INACTIVE = 3;

    protected $fillable = [
        'tenant_id',
        'channel_organization_id',
        'company_id',
        'assigned_user_id',
        'created_by_user_id',
        'updated_by_user_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'lifecycle_stage',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'lifecycle_stage' => 'integer',
            'meta' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ContactActivity::class);
    }

    public static function stageCodeFromString(string $stage): int
    {
        return match ($stage) {
            'prospect' => self::STAGE_PROSPECT,
            'customer' => self::STAGE_CUSTOMER,
            'inactive' => self::STAGE_INACTIVE,
            default => self::STAGE_LEAD,
        };
    }

    public function stageLabel(): string
    {
        return match ($this->lifecycle_stage) {
            self::STAGE_PROSPECT => 'prospect',
            self::STAGE_CUSTOMER => 'customer',
            self::STAGE_INACTIVE => 'inactive',
            default => 'lead',
        };
    }
}
