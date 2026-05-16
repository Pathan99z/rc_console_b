<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseMovement extends Model
{
    public const TYPE_ALLOCATE = 'allocate';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_ACTIVATE = 'activate';

    public const TYPE_REVOKE = 'revoke';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'from_entitlement_id',
        'to_entitlement_id',
        'to_organization_id',
        'movement_type',
        'units',
        'actor_user_id',
        'reference',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function fromEntitlement(): BelongsTo
    {
        return $this->belongsTo(LicenseEntitlement::class, 'from_entitlement_id');
    }

    public function toEntitlement(): BelongsTo
    {
        return $this->belongsTo(LicenseEntitlement::class, 'to_entitlement_id');
    }

    public function toOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'to_organization_id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
