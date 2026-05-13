<?php

use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('type', 20);
            $table->string('legal_name');
            $table->string('display_name');
            $table->string('registration_number', 100)->nullable();
            $table->string('tax_number', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('website')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('country', 120)->nullable();
            $table->string('postal_code', 40)->nullable();
            $table->string('onboarding_status', 30)->default(Organization::ONBOARDING_DRAFT);
            $table->string('status', 20)->default(Organization::STATUS_ACTIVE);
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'parent_organization_id']);
            $table->index(['tenant_id', 'onboarding_status']);
            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'type', 'display_name'], 'org_tenant_type_display_unique');
            $table->unique(['tenant_id', 'registration_number'], 'org_tenant_registration_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
