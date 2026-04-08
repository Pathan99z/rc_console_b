<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        DB::statement("
            UPDATE users
            SET status = CASE
                WHEN status = 'active' THEN '1'
                WHEN status = 'inactive' THEN '0'
                WHEN status = 'suspended' THEN '2'
                ELSE status
            END
            WHERE status IS NOT NULL
        ");

        DB::statement("
            UPDATE tenants
            SET status = CASE
                WHEN status = 'active' THEN '1'
                WHEN status = 'inactive' THEN '0'
                WHEN status = 'suspended' THEN '2'
                ELSE status
            END
            WHERE status IS NOT NULL
        ");

        if ($driver !== 'sqlite') {
            DB::statement("ALTER TABLE users MODIFY status TINYINT UNSIGNED NOT NULL DEFAULT 1");
            DB::statement("ALTER TABLE tenants MODIFY status TINYINT UNSIGNED NOT NULL DEFAULT 1");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver !== 'sqlite') {
            DB::statement("ALTER TABLE users MODIFY status VARCHAR(20) NOT NULL DEFAULT 'active'");
            DB::statement("ALTER TABLE tenants MODIFY status VARCHAR(20) NOT NULL DEFAULT 'active'");
        }

        DB::statement("
            UPDATE users
            SET status = CASE
                WHEN status = 1 THEN 'active'
                WHEN status = 0 THEN 'inactive'
                WHEN status = 2 THEN 'suspended'
                ELSE 'active'
            END
        ");

        DB::statement("
            UPDATE tenants
            SET status = CASE
                WHEN status = 1 THEN 'active'
                WHEN status = 0 THEN 'inactive'
                WHEN status = 2 THEN 'suspended'
                ELSE 'active'
            END
        ");
    }
};
