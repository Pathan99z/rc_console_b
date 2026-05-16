<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('roles')->updateOrInsert(
            ['code' => Role::CODE_RESELLER_SALES_MANAGER],
            [
                'name' => 'Reseller Sales Manager',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        DB::table('roles')->where('code', Role::CODE_RESELLER_SALES_MANAGER)->delete();
    }
};
