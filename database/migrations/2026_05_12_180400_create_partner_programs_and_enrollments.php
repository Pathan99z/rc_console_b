<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedTinyInteger('tier_level')->default(1);
            $table->decimal('default_commission_percent', 8, 4)->default(0);
            $table->json('rules')->nullable();
            $table->boolean('is_template')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('partner_program_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('partner_program_id')->constrained('partner_programs')->cascadeOnDelete();
            $table->string('tier_code', 64);
            $table->decimal('commission_percent', 8, 4)->nullable();
            $table->string('status', 32)->default('active');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id', 'status'], 'ppe_tenant_org_status_idx');
        });

        $now = now();
        $templates = [
            ['code' => 'silver', 'name' => 'Silver', 'tier_level' => 1, 'default_commission_percent' => 5.0],
            ['code' => 'gold', 'name' => 'Gold', 'tier_level' => 2, 'default_commission_percent' => 10.0],
            ['code' => 'platinum', 'name' => 'Platinum', 'tier_level' => 3, 'default_commission_percent' => 15.0],
            ['code' => 'custom', 'name' => 'Custom', 'tier_level' => 0, 'default_commission_percent' => 0.0],
        ];

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            foreach ($templates as $tpl) {
                DB::table('partner_programs')->updateOrInsert(
                    ['tenant_id' => $tenantId, 'code' => $tpl['code']],
                    [
                        'name' => $tpl['name'],
                        'tier_level' => $tpl['tier_level'],
                        'default_commission_percent' => $tpl['default_commission_percent'],
                        'rules' => json_encode(['targets' => [], 'benefits' => []]),
                        'is_template' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_program_enrollments');
        Schema::dropIfExists('partner_programs');
    }
};
