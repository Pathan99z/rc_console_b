<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollateralDownload extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'collateral_id',
        'user_id',
        'partner_organization_id',
        'ip_address',
        'user_agent',
        'downloaded_at',
    ];

    protected function casts(): array
    {
        return [
            'downloaded_at' => 'datetime',
        ];
    }

    public function collateral(): BelongsTo
    {
        return $this->belongsTo(Collateral::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
