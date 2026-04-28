<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->unsignedTinyInteger('probability')->nullable()->after('currency_code');
            $table->index(['tenant_id', 'probability']);
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropIndex('deals_tenant_id_probability_index');
            $table->dropColumn('probability');
        });
    }
};
