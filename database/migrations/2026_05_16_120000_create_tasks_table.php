<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scope_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 16)->default('medium');
            $table->string('status', 32)->default('pending');
            $table->timestamp('due_at')->nullable();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('related_type', 50)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'assignee_user_id', 'status'], 'tasks_tenant_assignee_status_idx');
            $table->index(['tenant_id', 'scope_organization_id', 'status'], 'tasks_tenant_scope_org_status_idx');
            $table->index(['tenant_id', 'due_at'], 'tasks_tenant_due_at_idx');
            $table->index(['tenant_id', 'related_type', 'related_id'], 'tasks_tenant_related_idx');
            $table->index(['tenant_id', 'created_by_user_id'], 'tasks_tenant_creator_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
