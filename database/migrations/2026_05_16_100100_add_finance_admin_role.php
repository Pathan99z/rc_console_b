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
            ['code' => Role::CODE_FINANCE_ADMIN],
            ['name' => 'Finance Admin', 'created_at' => $now, 'updated_at' => $now]
        );
    }

    public function down(): void
    {
        DB::table('roles')->where('code', Role::CODE_FINANCE_ADMIN)->delete();
    }
};
