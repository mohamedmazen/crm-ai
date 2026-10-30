<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_user_id')->nullable()->after('close_reason_id');
            $table->timestamp('assigned_at')->nullable()->after('assigned_user_id');

            $table->index(['workspace_id', 'assigned_user_id']);
            $table->foreign('assigned_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('lead_assignment_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pipeline_id')->unique();
            $table->enum('mode', ['manual', 'round_robin', 'load_balanced'])->default('manual');
            $table->unsignedBigInteger('last_assigned_user_id')->nullable();
            $table->timestamps();

            $table->foreign('pipeline_id')->references('id')->on('lead_pipelines')->cascadeOnDelete();
            $table->foreign('last_assigned_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_assignment_configs');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['assigned_user_id']);
            $table->dropIndex(['workspace_id', 'assigned_user_id']);
            $table->dropColumn(['assigned_user_id', 'assigned_at']);
        });
    }
};
