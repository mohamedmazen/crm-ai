<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_kb_chunks', function (Blueprint $table) {
            // Section heading (and future per-chunk metadata) captured while
            // chunking, so retrieval can show which part of a document a hit
            // came from.
            $table->json('meta')->nullable()->after('embedding');
        });
    }

    public function down(): void
    {
        Schema::table('ai_kb_chunks', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
