<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('title');
            $table->string('contact_email')->nullable();
            $table->string('contact_first_name')->nullable();
            $table->string('contact_last_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('phone')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 32)->default('new');
            $table->string('approval_status', 32)->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'partner_organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_leads');
    }
};
