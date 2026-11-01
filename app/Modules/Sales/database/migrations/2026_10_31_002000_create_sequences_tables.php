<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name', 128);
            $table->enum('status', ['draft', 'active', 'paused'])->default('draft');
            $table->boolean('stop_on_reply')->default(true);
            $table->boolean('stop_on_won')->default(true);
            $table->boolean('respect_hours')->default(true);
            $table->timestamps();

            $table->index('workspace_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::create('sequence_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sequence_id');
            $table->unsignedInteger('position');
            $table->enum('channel', ['email', 'whatsapp'])->default('email');
            $table->unsignedInteger('delay_hours')->default(24);
            $table->string('subject', 256)->nullable();
            $table->text('body');
            $table->json('condition')->nullable();
            $table->timestamps();

            $table->unique(['sequence_id', 'position']);
            $table->foreign('sequence_id')->references('id')->on('sequences')->cascadeOnDelete();
        });

        Schema::create('sequence_enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sequence_id');
            $table->unsignedBigInteger('lead_id');
            $table->unsignedInteger('current_step')->default(0);
            $table->enum('status', ['active', 'completed', 'stopped', 'bounced'])->default('active');
            $table->string('stop_reason', 64)->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->unique(['sequence_id', 'lead_id']);
            $table->index(['next_run_at', 'status']);
            $table->foreign('sequence_id')->references('id')->on('sequences')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
        });

        Schema::create('sequence_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedInteger('step_position');
            $table->enum('type', ['sent', 'open', 'click', 'reply', 'bounce', 'unsubscribe']);
            $table->timestamp('created_at')->useCurrent();

            $table->index('enrollment_id');
            $table->foreign('enrollment_id')->references('id')->on('sequence_enrollments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequence_events');
        Schema::dropIfExists('sequence_enrollments');
        Schema::dropIfExists('sequence_steps');
        Schema::dropIfExists('sequences');
    }
};
