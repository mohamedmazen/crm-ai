<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->nullable()->index();
            $table->string('waba_id', 64)->nullable()->index();
            $table->string('phone_number_id', 64)->nullable()->index();
            // Idempotency key from the entry's message/status ids. Message
            // events store a plain sha256 (64 chars); other events prefix the
            // hash with the WABA id, so the column allows up to 191 chars
            // (also the safe index length for utf8mb4 on older MySQL).
            $table->string('event_key', 191)->unique();
            $table->string('object', 64)->nullable();
            $table->string('event_type', 32)->default('unknown')->index();
            $table->json('payload');
            // received | processed | failed
            $table->string('processing_status', 20)->default('received')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_events');
    }
};
