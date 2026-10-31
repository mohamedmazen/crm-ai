<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('report_type', 64);
            $table->json('filters')->nullable();
            $table->enum('frequency', ['daily', 'weekly', 'monthly']);
            // CSV is the only implemented format today; the enum leaves room for PDF.
            $table->enum('format', ['csv', 'pdf'])->default('csv');
            $table->json('recipients');
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'report_type']);
            $table->index('next_run_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
