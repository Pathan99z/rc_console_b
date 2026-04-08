<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    public const STATUS_INACTIVE = 0;
    public const STATUS_ACTIVE = 1;
    public const STATUS_SUSPENDED = 2;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            $tenant->status ??= self::STATUS_ACTIVE;
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public static function statusCodeFromString(string $status): int
    {
        return match ($status) {
            'inactive' => self::STATUS_INACTIVE,
            'suspended' => self::STATUS_SUSPENDED,
            default => self::STATUS_ACTIVE,
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_INACTIVE => 'inactive',
            self::STATUS_SUSPENDED => 'suspended',
            default => 'active',
        };
    }
}
