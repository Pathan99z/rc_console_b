<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_accruals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('partner_program_enrollment_id')->nullable()->constrained('partner_program_enrollments')->nullOnDelete();
            $table->foreignId('payment_record_id')->nullable()->constrained('payment_records')->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->decimal('base_amount', 15, 2);
            $table->decimal('commission_amount', 15, 2);
            $table->string('currency_code', 8)->default('ZAR');
            $table->string('calculation_type', 32)->default('percentage');
            $table->string('status', 32)->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('rule_snapshot')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'partner_organization_id', 'status'], 'ca_tenant_partner_status_idx');
            $table->index(['payment_record_id'], 'ca_payment_record_idx');
        });

        Schema::create('license_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('holder_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('parent_entitlement_id')->nullable()->constrained('license_entitlements')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedInteger('units_total');
            $table->unsignedInteger('units_consumed')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'holder_organization_id'], 'le_tenant_holder_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_entitlements');
        Schema::dropIfExists('commission_accruals');
    }
};
