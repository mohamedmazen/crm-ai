<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            // Which workspace paid for the call. Without this a run is only
            // reachable through its chatbot, so non-chatbot AI (email copy,
            // lead coaching, call summaries) could neither be reported nor
            // exported per tenant.
            $table->unsignedBigInteger('workspace_id')->nullable()->after('id');

            // The feature that issued the call, e.g. `lead_coach`. Turns the
            // run log into a per-feature cost breakdown.
            $table->string('feature', 32)->nullable()->after('conversation_id');

            $table->index(['workspace_id', 'feature', 'created_at'], 'ai_runs_workspace_feature_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropIndex('ai_runs_workspace_feature_created_idx');
            $table->dropColumn(['workspace_id', 'feature']);
        });
    }
};
