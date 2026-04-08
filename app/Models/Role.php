<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    public const CODE_GLOBAL_ADMIN = 'global_admin';
    public const CODE_COMPANY_ADMIN = 'company_admin';
    public const CODE_USER = 'user';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
