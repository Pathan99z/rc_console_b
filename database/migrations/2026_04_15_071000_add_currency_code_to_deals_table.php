<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->string('currency_code', 3)->nullable()->after('estimated_value');
            $table->index(['tenant_id', 'currency_code']);
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropIndex('deals_tenant_id_currency_code_index');
            $table->dropColumn('currency_code');
        });
    }
};
