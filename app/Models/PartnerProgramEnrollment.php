<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerProgramEnrollment extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'organization_id',
        'partner_program_id',
        'tier_code',
        'commission_percent',
        'status',
        'starts_on',
        'ends_on',
        'metadata',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'metadata' => 'array',
            'commission_percent' => 'decimal:4',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(PartnerProgram::class, 'partner_program_id');
    }

    protected static function booted(): void
    {
        static::creating(function (PartnerProgramEnrollment $e): void {
            if ($e->commission_percent === null && $e->partner_program_id) {
                $program = PartnerProgram::query()->find($e->partner_program_id);
                if ($program) {
                    $e->commission_percent = $program->default_commission_percent;
                }
            }
        });
    }
}
