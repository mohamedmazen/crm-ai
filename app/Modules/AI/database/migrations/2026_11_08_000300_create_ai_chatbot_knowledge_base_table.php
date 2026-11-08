<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chatbot_knowledge_base', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chatbot_id')->constrained('ai_chatbots')->cascadeOnDelete();
            $table->foreignId('kb_id')->constrained('ai_knowledge_bases')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['chatbot_id', 'kb_id']);
            $table->index('kb_id');
        });

        // Backfill the single knowledge base each chatbot was limited to before
        // the pivot existed, so no bot loses its training data on deploy.
        DB::table('ai_chatbots')
            ->whereNotNull('ai_kb_id')
            ->orderBy('id')
            ->chunkById(200, function ($chatbots): void {
                $rows = [];

                foreach ($chatbots as $chatbot) {
                    $rows[] = [
                        'chatbot_id' => $chatbot->id,
                        'kb_id' => $chatbot->ai_kb_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                DB::table('ai_chatbot_knowledge_base')->insertOrIgnore($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chatbot_knowledge_base');
    }
};
