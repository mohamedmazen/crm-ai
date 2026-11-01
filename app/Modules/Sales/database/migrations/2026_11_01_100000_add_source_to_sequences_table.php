<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sequences a user builds are the default; the AI SDR owns one system
        // sequence per workspace to reuse the delivery path, and that row must
        // never appear in the builder or count against the plan's sequence limit.
        Schema::table('sequences', function (Blueprint $table) {
            $table->enum('source', ['user', 'ai_sdr'])->default('user')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('sequences', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
