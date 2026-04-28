<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collaterals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('type', 100);
            $table->string('file_key');
            $table->string('file_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'product_id']);
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'file_type']);
            $table->index(['tenant_id', 'created_by_user_id']);
            $table->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaterals');
    }
};
