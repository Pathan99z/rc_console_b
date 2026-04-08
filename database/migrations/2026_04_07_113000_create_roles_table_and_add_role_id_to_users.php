<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->timestamps();
        });

        $now = now();
        DB::table('roles')->insert([
            ['name' => 'Global Admin', 'code' => Role::CODE_GLOBAL_ADMIN, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Company Admin', 'code' => Role::CODE_COMPANY_ADMIN, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'User', 'code' => Role::CODE_USER, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('role_id')->nullable()->after('tenant_id')->constrained('roles')->nullOnDelete();
            $table->index('role_id');
        });

        $roleMap = DB::table('roles')->pluck('id', 'code');

        DB::table('users')
            ->where('role', Role::CODE_GLOBAL_ADMIN)
            ->update(['role_id' => $roleMap[Role::CODE_GLOBAL_ADMIN] ?? null]);
        DB::table('users')
            ->where('role', Role::CODE_COMPANY_ADMIN)
            ->update(['role_id' => $roleMap[Role::CODE_COMPANY_ADMIN] ?? null]);
        DB::table('users')
            ->where('role', Role::CODE_USER)
            ->orWhereNull('role')
            ->update(['role_id' => $roleMap[Role::CODE_USER] ?? null]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('role_id');
        });

        Schema::dropIfExists('roles');
    }
};
