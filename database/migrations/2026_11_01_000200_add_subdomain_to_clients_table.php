<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Wildcard subdomain (<workspace>.<base domain>). Nullable so
            // existing tenants keep working until an admin assigns one.
            $table->string('subdomain', 63)->nullable()->unique()->after('name');
            $table->index('custom_domain');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['subdomain']);
            $table->dropIndex(['custom_domain']);
            $table->dropColumn('subdomain');
        });
    }
};
