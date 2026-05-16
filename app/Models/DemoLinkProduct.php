<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoLinkProduct extends Model
{
    protected $fillable = [
        'tenant_id',
        'demo_link_id',
        'product_id',
    ];

    public function demoLink(): BelongsTo
    {
        return $this->belongsTo(DemoLink::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
