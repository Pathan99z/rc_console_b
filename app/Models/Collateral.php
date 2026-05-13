<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Collateral extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'created_by_user_id',
        'updated_by_user_id',
        'name',
        'type',
        'file_key',
        'file_type',
        'file_size',
        'partner_visible',
        'resource_category',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'partner_visible' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
