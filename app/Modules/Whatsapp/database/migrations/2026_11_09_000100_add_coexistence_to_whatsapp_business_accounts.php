<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_business_accounts', function (Blueprint $table) {
            // WhatsApp Business app coexistence (Embedded Signup v4,
            // featureType=whatsapp_business_app_onboarding). Standard Cloud API
            // accounts keep `is_coexistence=false` and status `none`.
            $table->boolean('is_coexistence')->default(false)->after('status');
            $table->string('coexistence_status', 20)->default('none')->after('is_coexistence');
            $table->timestamp('coexistence_linked_at')->nullable()->after('coexistence_status');
            $table->timestamp('coexistence_disconnected_at')->nullable()->after('coexistence_linked_at');
            $table->string('coexistence_disconnect_reason', 64)->nullable()->after('coexistence_disconnected_at');

            // History + contacts sync is offered once, inside the 24h window
            // after onboarding. `consented` means the customer opted in but the
            // request has not been sent to Meta yet.
            $table->string('history_sync_status', 20)->default('none')->after('coexistence_disconnect_reason');
            $table->timestamp('history_sync_consented_at')->nullable()->after('history_sync_status');
            $table->timestamp('coexistence_last_sync_at')->nullable()->after('history_sync_consented_at');

            $table->index('coexistence_status', 'whatsapp_waba_coexistence_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_business_accounts', function (Blueprint $table) {
            $table->dropIndex('whatsapp_waba_coexistence_status_idx');
            $table->dropColumn([
                'is_coexistence',
                'coexistence_status',
                'coexistence_linked_at',
                'coexistence_disconnected_at',
                'coexistence_disconnect_reason',
                'history_sync_status',
                'history_sync_consented_at',
                'coexistence_last_sync_at',
            ]);
        });
    }
};
