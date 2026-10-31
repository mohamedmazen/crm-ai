<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_trigger_dispatches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('automation_id');
            $table->string('trigger_type', 64);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->string('bucket', 64);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            // One dispatch per automation + subject + window. insertOrIgnore() against
            // this key is what makes the scheduled scans idempotent under retries.
            $table->unique(
                ['automation_id', 'trigger_type', 'subject_type', 'subject_id', 'bucket'],
                'automation_trigger_dispatches_unique'
            );
            $table->index(['workspace_id', 'trigger_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_trigger_dispatches');
    }
};
