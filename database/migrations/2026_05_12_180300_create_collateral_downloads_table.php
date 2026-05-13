<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collateral_downloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collateral_id')->constrained('collaterals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('partner_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'collateral_id', 'created_at']);
            $table->index(['partner_organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collateral_downloads');
    }
};
