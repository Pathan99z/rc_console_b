<?php

use App\Models\PartnerProgram;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('partner_programs')) {
            return;
        }

        Schema::table('partner_programs', function (Blueprint $table): void {
            if (! Schema::hasColumn('partner_programs', 'description')) {
                $table->text('description')->nullable();
            }
            if (! Schema::hasColumn('partner_programs', 'status')) {
                $table->string('status', 32)->default(PartnerProgram::STATUS_ACTIVE);
            }
            if (! Schema::hasColumn('partner_programs', 'metadata')) {
                $table->json('metadata')->nullable();
            }
        });

        if (Schema::hasColumn('partner_programs', 'status')) {
            DB::table('partner_programs')->whereNull('status')->update(['status' => PartnerProgram::STATUS_ACTIVE]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('partner_programs')) {
            return;
        }

        Schema::table('partner_programs', function (Blueprint $table): void {
            if (Schema::hasColumn('partner_programs', 'metadata')) {
                $table->dropColumn('metadata');
            }
            if (Schema::hasColumn('partner_programs', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('partner_programs', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
