<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('file_key');
            $table->string('file_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'quote_id']);
            $table->index(['tenant_id', 'uploaded_by_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_attachments');
    }
};
