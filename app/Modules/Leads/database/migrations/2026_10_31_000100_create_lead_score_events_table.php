<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_score_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('event', 64);
            $table->smallInteger('points');
            $table->string('reason', 256)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Every scoring read is "this lead's events", and decay looks for an
            // event newer than the lead's last activity — both served by this index.
            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_score_events');
    }
};
