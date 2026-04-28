<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->unsignedBigInteger('last_quote_id')->nullable()->after('pipeline_stage_id');
            $table->index(['tenant_id', 'last_quote_id']);
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropIndex('deals_tenant_id_last_quote_id_index');
            $table->dropColumn('last_quote_id');
        });
    }
};
