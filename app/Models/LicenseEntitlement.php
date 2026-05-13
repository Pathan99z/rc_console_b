<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseEntitlement extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'holder_organization_id',
        'parent_entitlement_id',
        'product_id',
        'units_total',
        'units_consumed',
        'notes',
        'created_by_user_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function holderOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'holder_organization_id');
    }

    public function parentEntitlement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_entitlement_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
