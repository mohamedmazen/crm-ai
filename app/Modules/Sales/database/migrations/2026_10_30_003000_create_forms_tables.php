<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name', 128);
            $table->string('slug', 128);
            $table->enum('status', ['draft', 'published', 'archived'])->default('published');
            $table->unsignedBigInteger('pipeline_id')->nullable();
            $table->unsignedBigInteger('target_stage_id')->nullable();
            $table->enum('assignment_mode', ['none', 'specific', 'round_robin'])->default('none');
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->json('auto_tags')->nullable();
            $table->enum('thank_you_type', ['message', 'redirect'])->default('message');
            $table->text('thank_you_value')->nullable();
            $table->enum('spam_protection', ['none', 'turnstile', 'recaptcha'])->default('none');
            $table->json('settings')->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('submissions_count')->default(0);
            $table->timestamps();

            $table->index('workspace_id');
            $table->unique(['workspace_id', 'slug']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('pipeline_id')->references('id')->on('lead_pipelines')->nullOnDelete();
            $table->foreign('target_stage_id')->references('id')->on('lead_pipeline_stages')->nullOnDelete();
            $table->foreign('assigned_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('form_id');
            $table->enum('type', [
                'text', 'email', 'phone', 'textarea', 'select',
                'checkbox', 'date', 'file', 'hidden', 'number',
            ])->default('text');
            $table->string('label', 256);
            $table->string('field_key', 64);
            $table->string('placeholder', 256)->nullable();
            $table->string('help_text', 256)->nullable();
            $table->boolean('required')->default(false);
            $table->json('options')->nullable();
            $table->string('maps_to', 64)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['form_id', 'sort_order']);
            $table->foreign('form_id')->references('id')->on('forms')->cascadeOnDelete();
        });

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('form_id');
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->json('payload');
            $table->string('ip', 45)->nullable();
            $table->string('utm_source', 128)->nullable();
            $table->string('utm_medium', 128)->nullable();
            $table->string('utm_campaign', 128)->nullable();
            $table->string('utm_term', 128)->nullable();
            $table->string('utm_content', 128)->nullable();
            $table->string('referrer', 512)->nullable();
            $table->timestamps();

            $table->index(['form_id', 'created_at']);
            $table->foreign('form_id')->references('id')->on('forms')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('forms');
    }
};
