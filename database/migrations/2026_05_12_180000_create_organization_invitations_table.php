<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->string('role_code', 64);
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedInteger('send_count')->default(0);
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id', 'status']);
            $table->index(['organization_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }
};
