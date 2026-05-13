<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_organization_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique('user_id');
        });

        if (Schema::hasColumn('users', 'organization_id')) {
            $rows = DB::table('users')->whereNotNull('organization_id')->get(['id', 'organization_id']);
            $now = now();
            foreach ($rows as $row) {
                DB::table('user_organization_assignments')->insert([
                    'user_id' => $row->id,
                    'organization_id' => $row->organization_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $driver = Schema::getConnection()->getDriverName();

            Schema::table('users', function (Blueprint $table) use ($driver): void {
                if ($driver === 'sqlite' && Schema::hasIndex('users', ['tenant_id', 'organization_id'])) {
                    $table->dropIndex(['tenant_id', 'organization_id']);
                }

                $table->dropConstrainedForeignId('organization_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('organizations')
                ->nullOnDelete();
            $table->index(['tenant_id', 'organization_id']);
        });

        $assignments = DB::table('user_organization_assignments')->get(['user_id', 'organization_id']);
        foreach ($assignments as $assignment) {
            DB::table('users')
                ->where('id', $assignment->user_id)
                ->update(['organization_id' => $assignment->organization_id]);
        }

        Schema::dropIfExists('user_organization_assignments');
    }
};
