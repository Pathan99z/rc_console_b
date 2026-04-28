<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('industry', 100)->nullable()->after('name');
            $table->string('company_type', 100)->nullable()->after('industry');
            $table->unsignedInteger('employees')->nullable()->after('company_type');
            $table->decimal('revenue', 15, 2)->nullable()->after('employees');
            $table->string('timezone', 100)->nullable()->after('revenue');
            $table->string('linkedin_url')->nullable()->after('timezone');
            $table->string('address')->nullable()->after('linkedin_url');
            $table->string('city', 120)->nullable()->after('address');
            $table->string('state', 120)->nullable()->after('city');
            $table->string('postal_code', 30)->nullable()->after('state');
            $table->string('country', 120)->nullable()->after('postal_code');
            $table->text('description')->nullable()->after('country');

            $table->index(['tenant_id', 'industry']);
            $table->index(['tenant_id', 'country']);
            $table->index(['tenant_id', 'company_type']);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'industry']);
            $table->dropIndex(['tenant_id', 'country']);
            $table->dropIndex(['tenant_id', 'company_type']);
            $table->dropColumn([
                'industry',
                'company_type',
                'employees',
                'revenue',
                'timezone',
                'linkedin_url',
                'address',
                'city',
                'state',
                'postal_code',
                'country',
                'description',
            ]);
        });
    }
};
