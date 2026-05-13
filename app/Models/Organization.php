<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    public const TYPE_COMPANY = 'company';

    public const TYPE_PARTNER = 'partner';

    public const TYPE_RESELLER = 'reseller';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const ONBOARDING_DRAFT = 'draft';

    public const ONBOARDING_PENDING_REVIEW = 'pending_review';

    public const ONBOARDING_APPROVED = 'approved';

    public const ONBOARDING_ACTIVE = 'active';

    public const ONBOARDING_SUSPENDED = 'suspended';

    public const ONBOARDING_REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'parent_organization_id',
        'type',
        'legal_name',
        'display_name',
        'registration_number',
        'tax_number',
        'email',
        'phone',
        'website',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'country',
        'postal_code',
        'onboarding_status',
        'status',
        'credit_limit',
        'metadata',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'credit_limit' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Organization $organization): void {
            $organization->status ??= self::STATUS_ACTIVE;
            $organization->onboarding_status ??= self::ONBOARDING_DRAFT;
            $organization->metadata ??= [];
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function parentOrganization(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_organization_id');
    }

    public function childOrganizations(): HasMany
    {
        return $this->hasMany(self::class, 'parent_organization_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }
}
