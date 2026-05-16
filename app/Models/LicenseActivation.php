<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseActivation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'license_entitlement_id',
        'license_movement_id',
        'contact_id',
        'company_id',
        'units',
        'activated_by_user_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(LicenseEntitlement::class, 'license_entitlement_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(LicenseMovement::class, 'license_movement_id');
    }
}
