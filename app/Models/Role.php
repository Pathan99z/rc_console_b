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

    public const CODE_FINANCE_ADMIN = 'finance_admin';

    public const CODE_USER = 'user';

    public const CODE_PARTNER_ADMIN = 'partner_admin';

    public const CODE_PARTNER_SALES_MANAGER = 'partner_sales_manager';

    public const CODE_PARTNER_SALES_CONSULTANT = 'partner_sales_consultant';

    public const CODE_RESELLER_ADMIN = 'reseller_admin';

    public const CODE_RESELLER_SALES_MANAGER = 'reseller_sales_manager';

    public const CODE_RESELLER_SALES_CONSULTANT = 'reseller_sales_consultant';

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
