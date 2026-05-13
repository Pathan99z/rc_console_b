<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->foreignId('partner_organization_id')->nullable()->after('tenant_id')->constrained('organizations')->nullOnDelete();
            $table->foreignId('partner_registered_by_user_id')->nullable()->after('partner_organization_id')->constrained('users')->nullOnDelete();
            $table->string('partner_opportunity_fingerprint', 64)->nullable()->after('partner_registered_by_user_id');
            $table->index(['tenant_id', 'partner_organization_id']);
        });

        Schema::table('deals', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'partner_organization_id', 'partner_opportunity_fingerprint'], 'deals_prm_opportunity_unique');
        });

        Schema::table('collaterals', function (Blueprint $table): void {
            $table->boolean('partner_visible')->default(true)->after('file_size');
            $table->string('resource_category', 64)->nullable()->after('partner_visible');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropUnique('deals_prm_opportunity_unique');
            $table->dropConstrainedForeignId('partner_organization_id');
            $table->dropConstrainedForeignId('partner_registered_by_user_id');
            $table->dropColumn('partner_opportunity_fingerprint');
        });

        Schema::table('collaterals', function (Blueprint $table): void {
            $table->dropColumn(['partner_visible', 'resource_category']);
        });
    }
};
