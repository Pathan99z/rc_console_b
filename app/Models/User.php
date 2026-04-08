<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Support\DomainConstants;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        static::addGlobalScope(new TenantScope());

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

    public function roleModel(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification());
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
