<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contacts imported from the WhatsApp Business app that did not fit in the
     * plan's contact cap. Soft landing: nothing is dropped, they wait here and
     * are imported after an upgrade or after existing contacts are removed.
     */
    public function up(): void
    {
        Schema::create('whatsapp_coexistence_pending_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('waba_id', 64);
            $table->string('phone', 32);
            $table->string('display_name', 128)->nullable();
            $table->string('wa_user_id', 64)->nullable();
            $table->string('source', 32)->default('history_sync');
            $table->string('status', 20)->default('pending');
            $table->string('reason', 64)->nullable();
            $table->unsignedBigInteger('imported_contact_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'phone']);
            $table->index(['workspace_id', 'status']);
            $table->index('waba_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_coexistence_pending_contacts');
    }
};
