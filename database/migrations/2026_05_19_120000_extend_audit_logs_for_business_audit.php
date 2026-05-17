<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('correlation_id', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->string('event_key', 160)->nullable();
            $table->string('source', 40)->nullable();
            $table->timestamp('immutable_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->index(['tenant_id', 'created_at'], 'audit_logs_tenant_created_at_index');
            $table->index(['tenant_id', 'event_key'], 'audit_logs_tenant_event_key_index');
            $table->index(['tenant_id', 'organization_id', 'created_at'], 'audit_logs_tenant_org_created_index');
            $table->index('correlation_id', 'audit_logs_correlation_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_tenant_created_at_index');
            $table->dropIndex('audit_logs_tenant_event_key_index');
            $table->dropIndex('audit_logs_tenant_org_created_index');
            $table->dropIndex('audit_logs_correlation_id_index');

            $table->dropForeign(['organization_id']);

            $table->dropColumn([
                'organization_id',
                'correlation_id',
                'metadata',
                'event_key',
                'source',
                'immutable_at',
                'archived_at',
            ]);
        });
    }
};
