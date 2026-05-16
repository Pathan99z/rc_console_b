<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->index(['tenant_id', 'channel_organization_id', 'status'], 'deals_tenant_channel_status_idx');
            $table->index(['tenant_id', 'channel_organization_id', 'created_at'], 'deals_tenant_channel_created_idx');
        });

        Schema::table('quotes', function (Blueprint $table): void {
            $table->index(['tenant_id', 'channel_organization_id', 'status'], 'quotes_tenant_channel_status_idx');
            $table->index(['tenant_id', 'channel_organization_id', 'created_at'], 'quotes_tenant_channel_created_idx');
        });

        Schema::table('payment_records', function (Blueprint $table): void {
            $table->index(['tenant_id', 'status', 'created_at'], 'payment_records_tenant_status_created_idx');
        });

        Schema::table('commission_accruals', function (Blueprint $table): void {
            $table->index(['tenant_id', 'partner_organization_id', 'created_at'], 'ca_tenant_partner_created_idx');
        });

        Schema::table('collateral_downloads', function (Blueprint $table): void {
            $table->index(['tenant_id', 'partner_organization_id', 'downloaded_at'], 'cd_tenant_partner_downloaded_idx');
        });
    }

    public function down(): void
    {
        Schema::table('collateral_downloads', function (Blueprint $table): void {
            $table->dropIndex('cd_tenant_partner_downloaded_idx');
        });

        Schema::table('commission_accruals', function (Blueprint $table): void {
            $table->dropIndex('ca_tenant_partner_created_idx');
        });

        Schema::table('payment_records', function (Blueprint $table): void {
            $table->dropIndex('payment_records_tenant_status_created_idx');
        });

        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropIndex('quotes_tenant_channel_status_idx');
            $table->dropIndex('quotes_tenant_channel_created_idx');
        });

        Schema::table('deals', function (Blueprint $table): void {
            $table->dropIndex('deals_tenant_channel_status_idx');
            $table->dropIndex('deals_tenant_channel_created_idx');
        });
    }
};
