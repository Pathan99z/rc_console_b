<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_payment_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('payfast_mode', 16)->default('sandbox');
            $table->string('merchant_id', 64)->nullable();
            $table->text('merchant_key_encrypted')->nullable();
            $table->text('passphrase_encrypted')->nullable();
            $table->string('return_url', 2048)->nullable();
            $table->string('cancel_url', 2048)->nullable();
            $table->string('notify_url', 2048)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_payment_settings');
    }
};
