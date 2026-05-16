<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DemoLink extends Model
{
    use SoftDeletes;

    public const STATUS_UP = 'up';

    public const STATUS_DOWN = 'down';

    public const STATUS_UNKNOWN = 'unknown';

    public const VISIBILITY_VIEW = 'view';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'created_by_user_id',
        'owner_organization_id',
        'title',
        'demo_url',
        'demo_username',
        'demo_password_encrypted',
        'description',
        'screenshot_path',
        'check_live_status',
        'last_checked_at',
        'last_status',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'check_live_status' => 'boolean',
            'is_active' => 'boolean',
            'last_checked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function ownerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'owner_organization_id');
    }

    public function visibilities(): HasMany
    {
        return $this->hasMany(DemoLinkVisibility::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'demo_link_products')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }
}
