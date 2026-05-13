<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartnerProgram extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'description',
        'tier_level',
        'default_commission_percent',
        'status',
        'rules',
        'metadata',
        'is_template',
    ];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'metadata' => 'array',
            'is_template' => 'boolean',
            'default_commission_percent' => 'decimal:4',
        ];
    }

    public function isActive(): bool
    {
        return ($this->status ?? self::STATUS_ACTIVE) === self::STATUS_ACTIVE;
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(PartnerProgramEnrollment::class);
    }
}
