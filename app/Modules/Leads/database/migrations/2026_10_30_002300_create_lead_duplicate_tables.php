<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_duplicate_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('match_type', 32)->nullable(); // phone, email, website
            $table->string('match_value', 256)->nullable();
            $table->enum('status', ['pending', 'merged', 'dismissed'])->default('pending');
            $table->timestamps();

            $table->index('workspace_id');
            $table->index(['workspace_id', 'status']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::create('lead_duplicate_group_members', function (Blueprint $table) {
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('lead_id');

            $table->primary(['group_id', 'lead_id']);
            $table->foreign('group_id')->references('id')->on('lead_duplicate_groups')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_duplicate_group_members');
        Schema::dropIfExists('lead_duplicate_groups');
    }
};

