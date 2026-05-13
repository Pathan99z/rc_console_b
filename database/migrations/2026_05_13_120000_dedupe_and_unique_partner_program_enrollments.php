<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('partner_program_enrollments')) {
            return;
        }

        $dupes = DB::select(
            'SELECT tenant_id, organization_id, partner_program_id, MAX(id) AS keep_id
             FROM partner_program_enrollments
             GROUP BY tenant_id, organization_id, partner_program_id
             HAVING COUNT(*) > 1'
        );

        foreach ($dupes as $row) {
            DB::table('partner_program_enrollments')
                ->where('tenant_id', $row->tenant_id)
                ->where('organization_id', $row->organization_id)
                ->where('partner_program_id', $row->partner_program_id)
                ->where('id', '<>', (int) $row->keep_id)
                ->delete();
        }

        Schema::table('partner_program_enrollments', function (Blueprint $table): void {
            $table->unique(
                ['tenant_id', 'organization_id', 'partner_program_id'],
                'ppe_tenant_org_program_unique'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('partner_program_enrollments')) {
            return;
        }

        Schema::table('partner_program_enrollments', function (Blueprint $table): void {
            $table->dropUnique('ppe_tenant_org_program_unique');
        });
    }
};
