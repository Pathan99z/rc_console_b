<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $roles = [
            ['name' => 'Partner Admin', 'code' => Role::CODE_PARTNER_ADMIN, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Partner Sales Manager', 'code' => Role::CODE_PARTNER_SALES_MANAGER, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Partner Sales Consultant', 'code' => Role::CODE_PARTNER_SALES_CONSULTANT, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Reseller Admin', 'code' => Role::CODE_RESELLER_ADMIN, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Reseller Sales Consultant', 'code' => Role::CODE_RESELLER_SALES_CONSULTANT, 'created_at' => $now, 'updated_at' => $now],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['code' => $role['code']],
                ['name' => $role['name'], 'updated_at' => $now, 'created_at' => $role['created_at']]
            );
        }
    }

    public function down(): void
    {
        DB::table('roles')
            ->whereIn('code', [
                Role::CODE_PARTNER_ADMIN,
                Role::CODE_PARTNER_SALES_MANAGER,
                Role::CODE_PARTNER_SALES_CONSULTANT,
                Role::CODE_RESELLER_ADMIN,
                Role::CODE_RESELLER_SALES_CONSULTANT,
            ])->delete();
    }
};
