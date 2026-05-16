<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['contacts', 'companies', 'deals', 'quotes'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->foreignId('channel_organization_id')
                    ->nullable()
                    ->after('tenant_id')
                    ->constrained('organizations')
                    ->nullOnDelete();
                $table->index(['tenant_id', 'channel_organization_id'], "{$tableName}_tenant_channel_org_idx");
            });
        }

        // Non-destructive backfill: only populate NULL channel_organization_id from existing data.
        DB::table('deals')
            ->whereNotNull('partner_organization_id')
            ->whereNull('channel_organization_id')
            ->update(['channel_organization_id' => DB::raw('partner_organization_id')]);

        DB::table('quotes')
            ->whereNotNull('deal_id')
            ->whereNull('channel_organization_id')
            ->orderBy('id')
            ->chunkById(200, function ($quotes): void {
                foreach ($quotes as $quote) {
                    $channelOrgId = DB::table('deals')
                        ->where('id', $quote->deal_id)
                        ->value('channel_organization_id');
                    if ($channelOrgId !== null) {
                        DB::table('quotes')
                            ->where('id', $quote->id)
                            ->whereNull('channel_organization_id')
                            ->update(['channel_organization_id' => $channelOrgId]);
                    }
                }
            });

        DB::table('contacts')
            ->whereNull('channel_organization_id')
            ->orderBy('id')
            ->chunkById(200, function ($contacts): void {
                foreach ($contacts as $contact) {
                    $channelOrgId = DB::table('deals')
                        ->where('contact_id', $contact->id)
                        ->whereNotNull('channel_organization_id')
                        ->orderByDesc('id')
                        ->value('channel_organization_id');
                    if ($channelOrgId !== null) {
                        DB::table('contacts')
                            ->where('id', $contact->id)
                            ->whereNull('channel_organization_id')
                            ->update(['channel_organization_id' => $channelOrgId]);
                    }
                }
            });

        DB::table('companies')
            ->whereNull('channel_organization_id')
            ->orderBy('id')
            ->chunkById(200, function ($companies): void {
                foreach ($companies as $company) {
                    $channelOrgId = DB::table('deals')
                        ->where('company_id', $company->id)
                        ->whereNotNull('channel_organization_id')
                        ->orderByDesc('id')
                        ->value('channel_organization_id');
                    if ($channelOrgId !== null) {
                        DB::table('companies')
                            ->where('id', $company->id)
                            ->whereNull('channel_organization_id')
                            ->update(['channel_organization_id' => $channelOrgId]);
                    }
                }
            });
    }

    public function down(): void
    {
        foreach (['quotes', 'deals', 'companies', 'contacts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropForeign(['channel_organization_id']);
                $table->dropIndex("{$tableName}_tenant_channel_org_idx");
            });
        }
    }
};
