<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_payout_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('account_holder_name');
            $table->string('bank_name')->nullable();
            $table->string('branch_name')->nullable();
            $table->text('account_number_encrypted');
            $table->string('ifsc_code', 32)->nullable();
            $table->string('swift_code', 32)->nullable();
            $table->string('currency_code', 8)->default('ZAR');
            $table->string('account_type', 32)->default('current');
            $table->boolean('is_primary')->default(false);
            $table->string('verification_status', 32)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id', 'is_primary'], 'opa_tenant_org_primary_idx');
        });

        Schema::create('payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('beneficiary_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('payout_number', 64);
            $table->string('status', 32)->default('draft');
            $table->string('currency_code', 8)->default('ZAR');
            $table->decimal('gross_amount', 15, 2)->default(0);
            $table->decimal('adjustment_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2)->default(0);
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 32)->nullable();
            $table->string('remittance_reference', 128)->nullable();
            $table->text('remarks')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('supporting_document_path')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'payout_number'], 'payouts_tenant_number_uniq');
            $table->index(['tenant_id', 'beneficiary_organization_id', 'status'], 'payouts_tenant_beneficiary_status_idx');
        });

        Schema::create('payout_line_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payout_id')->constrained('payouts')->cascadeOnDelete();
            $table->foreignId('commission_accrual_id')->constrained('commission_accruals')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('currency_code', 8)->default('ZAR');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('commission_accrual_id', 'pli_commission_accrual_uniq');
        });

        Schema::create('payout_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('batch_number', 64);
            $table->string('status', 32)->default('draft');
            $table->string('currency_code', 8)->default('ZAR');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'batch_number'], 'pb_tenant_batch_uniq');
        });

        Schema::create('payout_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payout_batch_id')->constrained('payout_batches')->cascadeOnDelete();
            $table->foreignId('payout_id')->constrained('payouts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['payout_batch_id', 'payout_id'], 'pbi_batch_payout_uniq');
        });

        Schema::create('payout_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('payout_id')->nullable()->constrained('payouts')->nullOnDelete();
            $table->string('type', 16);
            $table->decimal('amount', 15, 2);
            $table->string('currency_code', 8)->default('ZAR');
            $table->string('reason');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id'], 'pa_tenant_org_idx');
        });

        Schema::create('payout_disputes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payout_id')->constrained('payouts')->cascadeOnDelete();
            $table->foreignId('raised_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 32)->default('open');
            $table->text('description');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'payout_id', 'status'], 'pd_tenant_payout_status_idx');
        });

        Schema::table('commission_accruals', function (Blueprint $table): void {
            $table->unique('payment_record_id', 'ca_payment_record_uniq');
        });
    }

    public function down(): void
    {
        Schema::table('commission_accruals', function (Blueprint $table): void {
            $table->dropUnique('ca_payment_record_uniq');
        });

        Schema::dropIfExists('payout_disputes');
        Schema::dropIfExists('payout_adjustments');
        Schema::dropIfExists('payout_batch_items');
        Schema::dropIfExists('payout_batches');
        Schema::dropIfExists('payout_line_items');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('organization_payout_accounts');
    }
};
