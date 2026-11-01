<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per workspace: the AI SDR kill switch and the guards that keep
        // an autonomous sender from becoming an autonomous spammer.
        Schema::create('ai_sdr_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->boolean('enabled')->default(false);
            $table->enum('channel', ['email', 'whatsapp'])->default('email');
            $table->unsignedInteger('daily_cap')->default(20);
            $table->unsignedInteger('per_lead_cap')->default(3);
            $table->unsignedInteger('min_hours_between')->default(48);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique('workspace_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_sdr_settings');
    }
};
