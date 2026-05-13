<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerLead extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_QUALIFIED = 'qualified';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_LOST = 'lost';

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'partner_organization_id',
        'title',
        'contact_email',
        'contact_first_name',
        'contact_last_name',
        'company_name',
        'phone',
        'description',
        'status',
        'approval_status',
        'assigned_user_id',
        'created_by_user_id',
        'updated_by_user_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function partnerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'partner_organization_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
