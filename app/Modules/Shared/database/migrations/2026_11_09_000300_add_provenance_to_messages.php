<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a message came from, so coexistence history imports stay silent and
     * messages written from the WhatsApp Business app can be badged in the inbox.
     *
     * source: crm | whatsapp_business_app | coexistence_history
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('source', 24)->default('crm')->after('sent_by');
            $table->boolean('is_history_import')->default(false)->after('source');

            $table->index(['conversation_id', 'is_history_import'], 'messages_conv_history_idx');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_conv_history_idx');
            $table->dropColumn(['source', 'is_history_import']);
        });
    }
};
