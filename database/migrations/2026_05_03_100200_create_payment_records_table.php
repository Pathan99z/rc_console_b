<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('currency_code', 3)->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('transaction_id', 128)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'quote_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_records');
    }
};
