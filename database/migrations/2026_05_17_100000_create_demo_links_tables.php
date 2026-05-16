<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('owner_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('title');
            $table->string('demo_url', 2048);
            $table->string('demo_username', 255)->nullable();
            $table->text('demo_password_encrypted')->nullable();
            $table->text('description')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->boolean('check_live_status')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_status', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'owner_organization_id', 'is_active'], 'dl_tenant_owner_active_idx');
            $table->index(['tenant_id', 'created_by_user_id'], 'dl_tenant_creator_idx');
        });

        Schema::create('demo_link_visibility', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('demo_link_id')->constrained('demo_links')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->boolean('include_children')->default(false);
            $table->string('visibility_type', 32)->default('view');
            $table->timestamps();

            $table->unique(['demo_link_id', 'organization_id'], 'dlv_link_org_uniq');
            $table->index(['tenant_id', 'organization_id'], 'dlv_tenant_org_idx');
        });

        Schema::create('demo_link_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('demo_link_id')->constrained('demo_links')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['demo_link_id', 'product_id'], 'dlp_link_product_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_link_products');
        Schema::dropIfExists('demo_link_visibility');
        Schema::dropIfExists('demo_links');
    }
};
