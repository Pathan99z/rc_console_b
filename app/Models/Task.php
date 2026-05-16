<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use SoftDeletes;

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_MEDIUM = 'medium';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_CRITICAL = 'critical';

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const RELATED_CONTACT = 'contact';

    public const RELATED_COMPANY = 'company';

    public const RELATED_DEAL = 'deal';

    public const RELATED_QUOTE = 'quote';

    public const RELATED_PAYMENT_RECORD = 'payment_record';

    public const RELATED_PAYOUT = 'payout';

    public const RELATED_LICENSE_ENTITLEMENT = 'license_entitlement';

    public const RELATED_OTHER = 'other';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'scope_organization_id',
        'title',
        'description',
        'priority',
        'status',
        'due_at',
        'assignee_user_id',
        'created_by_user_id',
        'updated_by_user_id',
        'related_type',
        'related_id',
        'completed_at',
        'cancelled_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function scopeOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'scope_organization_id');
    }

    public function getIsOverdueAttribute(): bool
    {
        if ($this->due_at === null) {
            return false;
        }

        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            return false;
        }

        return $this->due_at->isPast();
    }

    /**
     * @return list<string>
     */
    public static function priorities(): array
    {
        return [
            self::PRIORITY_LOW,
            self::PRIORITY_MEDIUM,
            self::PRIORITY_HIGH,
            self::PRIORITY_CRITICAL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function relatedTypes(): array
    {
        return [
            self::RELATED_CONTACT,
            self::RELATED_COMPANY,
            self::RELATED_DEAL,
            self::RELATED_QUOTE,
            self::RELATED_PAYMENT_RECORD,
            self::RELATED_PAYOUT,
            self::RELATED_LICENSE_ENTITLEMENT,
            self::RELATED_OTHER,
        ];
    }
}
