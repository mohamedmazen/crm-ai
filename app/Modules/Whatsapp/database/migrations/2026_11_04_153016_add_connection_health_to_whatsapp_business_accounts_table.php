<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_business_accounts', function (Blueprint $table) {
            // Unix expiry reported by Meta's /debug_token endpoint. Null means
            // "unknown" (no app credentials to inspect the token, or a
            // never-expiring system user token).
            $table->timestamp('token_expires_at')->nullable()->after('credentials');
            $table->timestamp('token_checked_at')->nullable()->after('token_expires_at');
            $table->timestamp('last_error_at')->nullable()->after('token_checked_at');
            $table->string('last_error_code', 32)->nullable()->after('last_error_at');
            $table->text('last_error_message')->nullable()->after('last_error_code');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_business_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'token_expires_at',
                'token_checked_at',
                'last_error_at',
                'last_error_code',
                'last_error_message',
            ]);
        });
    }
};
