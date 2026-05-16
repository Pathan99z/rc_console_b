<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoLinkVisibility extends Model
{
    protected $table = 'demo_link_visibility';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'demo_link_id',
        'organization_id',
        'include_children',
        'visibility_type',
    ];

    protected function casts(): array
    {
        return [
            'include_children' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function demoLink(): BelongsTo
    {
        return $this->belongsTo(DemoLink::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
