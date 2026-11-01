<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->text('transcript')->nullable()->after('notes');
            $table->text('summary')->nullable()->after('transcript');
            $table->json('summary_data')->nullable()->after('summary');
            $table->timestamp('summary_generated_at')->nullable()->after('summary_data');
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropColumn(['transcript', 'summary', 'summary_data', 'summary_generated_at']);
        });
    }
};
