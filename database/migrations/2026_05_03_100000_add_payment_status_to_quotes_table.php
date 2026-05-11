<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->unsignedTinyInteger('payment_status')->default(0)->after('status');
            $table->index(['tenant_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'payment_status']);
            $table->dropColumn('payment_status');
        });
    }
};
