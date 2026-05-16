<?php

use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('channel_mode', 32)->nullable()->after('type');
            $table->index(['tenant_id', 'type', 'channel_mode'], 'org_tenant_type_channel_mode_idx');
        });

        $organizations = DB::table('organizations')->where('type', Organization::TYPE_RESELLER)->get(['id', 'parent_organization_id']);

        foreach ($organizations as $row) {
            $parentId = $row->parent_organization_id;
            if ($parentId === null) {
                $mode = Organization::CHANNEL_MODE_DIRECT;
            } else {
                $parentType = DB::table('organizations')->where('id', $parentId)->value('type');
                $mode = $parentType === Organization::TYPE_PARTNER
                    ? Organization::CHANNEL_MODE_PARTNER_MANAGED
                    : Organization::CHANNEL_MODE_DIRECT;
            }

            DB::table('organizations')->where('id', $row->id)->update(['channel_mode' => $mode]);
        }
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropIndex('org_tenant_type_channel_mode_idx');
            $table->dropColumn('channel_mode');
        });
    }
};
