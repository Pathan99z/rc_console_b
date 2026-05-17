<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Support\DomainConstants;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const STATUS_INACTIVE = 0;

    public const STATUS_ACTIVE = 1;

    public const STATUS_SUSPENDED = 2;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'team_id',
        'manager_id',
        'role_id',
        'role',
        'status',
        'data_scope',
        'name',
        'email',
        'password',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => 'integer',
            'data_scope' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (User $user): void {
            $user->role ??= 'user';
            $user->status ??= self::STATUS_ACTIVE;
            $user->data_scope ??= DomainConstants::DATA_SCOPE_SELF;
            $user->role_id ??= Role::query()->where('code', $user->role)->value('id');
            $user->role ??= Role::query()->whereKey($user->role_id)->value('code') ?? Role::CODE_USER;
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function organizationAssignment(): HasOne
    {
        return $this->hasOne(UserOrganizationAssignment::class);
    }

    /**
     * Primary organization (partner / reseller / optional company link) for channel users.
     * Company admins usually have no assignment; tenant + auto-seeded root company drive partner create.
     */
    public function primaryOrganizationId(): ?int
    {
        return $this->organizationAssignment?->organization_id;
    }

    public function roleModel(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function isGlobalAdmin(): bool
    {
        return $this->currentRoleCode() === Role::CODE_GLOBAL_ADMIN && $this->tenant_id === null;
    }

    public function isCompanyAdmin(): bool
    {
        return $this->currentRoleCode() === Role::CODE_COMPANY_ADMIN;
    }

    public function isFinanceAdmin(): bool
    {
        return $this->currentRoleCode() === Role::CODE_FINANCE_ADMIN;
    }

    public function isPartnerAdmin(): bool
    {
        return $this->currentRoleCode() === Role::CODE_PARTNER_ADMIN;
    }

    public function isResellerRole(): bool
    {
        return in_array($this->currentRoleCode(), [
            Role::CODE_RESELLER_ADMIN,
            Role::CODE_RESELLER_SALES_MANAGER,
            Role::CODE_RESELLER_SALES_CONSULTANT,
        ], true);
    }

    public function isPartnerChannelUser(): bool
    {
        return in_array($this->currentRoleCode(), [
            Role::CODE_PARTNER_ADMIN,
            Role::CODE_PARTNER_SALES_MANAGER,
            Role::CODE_PARTNER_SALES_CONSULTANT,
        ], true);
    }

    public function isPartnerPortalEligible(): bool
    {
        return $this->isPartnerChannelUser() || $this->isResellerRole();
    }

    public function currentRoleCode(): string
    {
        return $this->roleModel?->code ?? $this->role ?? Role::CODE_USER;
    }

    public static function statusCodeFromString(string $status): int
    {
        return match ($status) {
            'inactive' => self::STATUS_INACTIVE,
            'suspended' => self::STATUS_SUSPENDED,
            default => self::STATUS_ACTIVE,
        };
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_INACTIVE => 'inactive',
            self::STATUS_SUSPENDED => 'suspended',
            default => 'active',
        };
    }

    public function dataScopeLabel(): string
    {
        return $this->data_scope === DomainConstants::DATA_SCOPE_TEAM ? 'team' : 'self';
    }
}
