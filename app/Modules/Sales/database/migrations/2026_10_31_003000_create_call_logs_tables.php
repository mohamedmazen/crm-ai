<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bring-your-own telephony credentials, one row per workspace + provider.
        Schema::create('call_provider_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->enum('provider', ['twilio', 'telnyx']);
            $table->text('credentials');
            $table->string('caller_id', 32)->nullable();
            $table->boolean('default')->default(false);
            $table->timestamps();

            $table->unique(['workspace_id', 'provider']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('direction', ['outbound', 'inbound']);
            $table->string('from_number', 32)->nullable();
            $table->string('to_number', 32)->nullable();
            $table->unsignedInteger('duration_secs')->default(0);
            $table->enum('status', ['initiated', 'ringing', 'answered', 'completed', 'failed', 'no_answer'])->default('initiated');
            $table->string('recording_url', 512)->nullable();
            $table->text('notes')->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('provider_sid', 64)->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'lead_id']);
            $table->index(['workspace_id', 'created_at']);
            $table->index('provider_sid');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_logs');
        Schema::dropIfExists('call_provider_configs');
    }
};
