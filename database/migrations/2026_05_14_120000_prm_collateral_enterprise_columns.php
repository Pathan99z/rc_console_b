<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collaterals', function (Blueprint $table): void {
            $table->dropForeign(['product_id']);
        });

        Schema::table('collaterals', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->change();
        });

        Schema::table('collaterals', function (Blueprint $table): void {
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });

        Schema::table('collaterals', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name');
            $table->boolean('reseller_visible')->default(false)->after('partner_visible');
            $table->string('status', 32)->default('active')->after('reseller_visible');
            $table->json('metadata')->nullable()->after('status');
        });

        Schema::table('collaterals', function (Blueprint $table): void {
            $table->boolean('partner_visible')->default(false)->change();
        });

        DB::table('collaterals')->whereNull('resource_category')->update(['resource_category' => 'general']);

        Schema::table('collateral_downloads', function (Blueprint $table): void {
            $table->timestamp('downloaded_at')->nullable()->after('user_agent');
        });

        DB::table('collateral_downloads')->whereNull('downloaded_at')->update(['downloaded_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('collateral_downloads', function (Blueprint $table): void {
            $table->dropColumn('downloaded_at');
        });

        Schema::table('collaterals', function (Blueprint $table): void {
            $table->dropColumn(['description', 'reseller_visible', 'status', 'metadata']);
        });

        Schema::table('collaterals', function (Blueprint $table): void {
            $table->dropForeign(['product_id']);
        });

        Schema::table('collaterals', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable(false)->change();
        });

        Schema::table('collaterals', function (Blueprint $table): void {
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::table('collaterals', function (Blueprint $table): void {
            $table->boolean('partner_visible')->default(true)->change();
        });
    }
};
