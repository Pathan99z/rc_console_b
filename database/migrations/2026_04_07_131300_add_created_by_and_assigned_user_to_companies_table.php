<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->foreignId('created_by_user_id')->nullable()->after('tenant_id')->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->after('created_by_user_id')->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'created_by_user_id']);
            $table->index(['tenant_id', 'assigned_user_id']);
        });

        DB::table('companies')->update([
            'created_by_user_id' => null,
            'assigned_user_id' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropConstrainedForeignId('created_by_user_id');
        });
    }
};
