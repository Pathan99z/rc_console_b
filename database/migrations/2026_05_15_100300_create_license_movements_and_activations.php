<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_entitlement_id')->nullable()->constrained('license_entitlements')->nullOnDelete();
            $table->foreignId('to_entitlement_id')->nullable()->constrained('license_entitlements')->nullOnDelete();
            $table->foreignId('to_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('movement_type', 32);
            $table->unsignedInteger('units');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'movement_type'], 'lm_tenant_type_idx');
            $table->index(['from_entitlement_id'], 'lm_from_entitlement_idx');
            $table->index(['to_organization_id'], 'lm_to_org_idx');
        });

        Schema::create('license_activations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('license_entitlement_id')->constrained('license_entitlements')->cascadeOnDelete();
            $table->foreignId('license_movement_id')->nullable()->constrained('license_movements')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->unsignedInteger('units');
            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'license_entitlement_id'], 'la_tenant_entitlement_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_activations');
        Schema::dropIfExists('license_movements');
    }
};
