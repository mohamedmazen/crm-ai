<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_provider_configs', function (Blueprint $table) {
            $table->string('provider', 64)->change();
            $table->string('default_model_chat', 128)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_provider_configs', function (Blueprint $table) {
            $table->string('default_model_chat', 64)->nullable()->change();
            $table->enum('provider', ['openai', 'anthropic', 'gemini'])->change();
        });
    }
};
