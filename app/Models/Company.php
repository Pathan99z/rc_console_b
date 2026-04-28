<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    public const STATUS_INACTIVE = 0;
    public const STATUS_ACTIVE = 1;

    protected $fillable = [
        'tenant_id',
        'created_by_user_id',
        'assigned_user_id',
        'name',
        'industry',
        'company_type',
        'employees',
        'revenue',
        'timezone',
        'linkedin_url',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'description',
        'email',
        'phone',
        'website',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'employees' => 'integer',
            'revenue' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function statusLabel(): string
    {
        return $this->status === self::STATUS_ACTIVE ? 'active' : 'inactive';
    }
}
