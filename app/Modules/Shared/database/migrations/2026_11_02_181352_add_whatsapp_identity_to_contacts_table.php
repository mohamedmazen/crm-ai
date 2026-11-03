<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WhatsApp business-scoped user IDs (BSUID) and usernames.
     *
     * Users who adopt a WhatsApp username can hide their phone number, and Meta
     * then identifies them by a BSUID (e.g. "US.13491208655302741918") instead of
     * a phone number. Storing it alongside the (now optional) phone number keeps
     * one contact record per person even before they share a number.
     *
     * The unique index is (workspace_id, wa_user_id); MySQL allows multiple NULLs,
     * so contacts without a WhatsApp identity are unaffected.
     *
     * conversations.external_thread_id is widened from 128 to 191 because a BSUID
     * can be a two-character country code, a dot, then up to 128 alphanumerics
     * (131 characters) — longer than the column previously allowed.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('wa_user_id', 191)->nullable()->after('phone_e164');
            $table->string('wa_username', 64)->nullable()->after('wa_user_id');

            $table->unique(['workspace_id', 'wa_user_id']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->string('external_thread_id', 191)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('external_thread_id', 128)->nullable()->change();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'wa_user_id']);
            $table->dropColumn(['wa_user_id', 'wa_username']);
        });
    }
};
