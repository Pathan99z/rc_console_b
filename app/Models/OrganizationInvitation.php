<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationInvitation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'organization_id',
        'email',
        'token_hash',
        'role_code',
        'invited_by_user_id',
        'status',
        'expires_at',
        'accepted_at',
        'terms_accepted_at',
        'last_sent_at',
        'send_count',
        'accepted_user_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invitedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    public function isPendingAndValid(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
