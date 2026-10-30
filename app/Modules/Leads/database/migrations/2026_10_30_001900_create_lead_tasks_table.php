<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 256);
            $table->text('description')->nullable();
            $table->string('type', 32)->default('todo');
            $table->string('priority', 16)->default('medium');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'assigned_user_id', 'due_at']);
            $table->index(['lead_id', 'completed_at']);
            $table->index(['workspace_id', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_tasks');
    }
};
