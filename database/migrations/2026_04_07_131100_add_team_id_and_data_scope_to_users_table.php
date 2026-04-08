<?php

use App\Support\DomainConstants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('team_id')->nullable()->after('tenant_id')->constrained('teams')->nullOnDelete();
            $table->tinyInteger('data_scope')->default(DomainConstants::DATA_SCOPE_SELF)->after('status');
            $table->index('team_id');
            $table->index('data_scope');
        });

        DB::table('users')
            ->whereNull('data_scope')
            ->update(['data_scope' => DomainConstants::DATA_SCOPE_SELF]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('team_id');
            $table->dropColumn('data_scope');
        });
    }
};
