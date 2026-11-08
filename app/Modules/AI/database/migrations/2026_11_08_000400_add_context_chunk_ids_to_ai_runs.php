<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            // Knowledge-base chunks injected into the prompt for this run, so
            // the UI can show which passages the bot actually answered from.
            $table->json('context_chunk_ids')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropColumn('context_chunk_ids');
        });
    }
};
