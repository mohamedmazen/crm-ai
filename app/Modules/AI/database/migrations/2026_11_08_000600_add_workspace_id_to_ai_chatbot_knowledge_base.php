<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carries the tenant on the chatbot ↔ knowledge-base pivot and makes the
 * database itself refuse a cross-tenant link.
 *
 * Until now `ai_chatbot_knowledge_base` had no workspace column, so isolation
 * relied on the controller validating ownership. A bad row (manual insert,
 * seeder, future bug) could link a chatbot to another tenant's knowledge base.
 *
 * The composite foreign keys below tie the pivot's `workspace_id` to both the
 * chatbot's and the knowledge base's workspace, so a mismatched row cannot be
 * stored at all — the strongest form of the guarantee the model-level filter
 * only approximates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chatbot_knowledge_base', function (Blueprint $table) {
            $table->unsignedBigInteger('workspace_id')->nullable()->after('id');
        });

        // Backfill from the owning chatbot: a pivot row can only ever belong to
        // the workspace that owns its chatbot.
        DB::table('ai_chatbot_knowledge_base')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                $workspaces = DB::table('ai_chatbots')
                    ->whereIn('id', collect($rows)->pluck('chatbot_id')->all())
                    ->pluck('workspace_id', 'id');

                foreach ($rows as $row) {
                    if (isset($workspaces[$row->chatbot_id])) {
                        DB::table('ai_chatbot_knowledge_base')
                            ->where('id', $row->id)
                            ->update(['workspace_id' => $workspaces[$row->chatbot_id]]);
                    }
                }
            });

        // Drop any legacy row that links a chatbot to a knowledge base in a
        // different workspace (impossible once the foreign keys exist, and
        // unreachable/unsafe today). This is what would otherwise make the
        // constraint creation fail on production data.
        $inconsistent = DB::table('ai_chatbot_knowledge_base as pivot')
            ->join('ai_chatbots as chatbot', 'chatbot.id', '=', 'pivot.chatbot_id')
            ->join('ai_knowledge_bases as kb', 'kb.id', '=', 'pivot.kb_id')
            ->whereColumn('chatbot.workspace_id', '!=', 'kb.workspace_id')
            ->pluck('pivot.id');

        if ($inconsistent->isNotEmpty()) {
            DB::table('ai_chatbot_knowledge_base')->whereIn('id', $inconsistent)->delete();
        }

        Schema::table('ai_chatbot_knowledge_base', function (Blueprint $table) {
            $table->unsignedBigInteger('workspace_id')->change();
            $table->index('workspace_id');
        });

        // Unique keys `(id, workspace_id)` on both parents are required as the
        // referenced side of the composite foreign keys.
        Schema::table('ai_chatbots', function (Blueprint $table) {
            $table->unique(['id', 'workspace_id'], 'ai_chatbots_id_workspace_unique');
        });

        Schema::table('ai_knowledge_bases', function (Blueprint $table) {
            $table->unique(['id', 'workspace_id'], 'ai_knowledge_bases_id_workspace_unique');
        });

        Schema::table('ai_chatbot_knowledge_base', function (Blueprint $table) {
            $table->foreign(['chatbot_id', 'workspace_id'], 'ai_cb_kb_chatbot_ws_foreign')
                ->references(['id', 'workspace_id'])->on('ai_chatbots')
                ->cascadeOnDelete();

            $table->foreign(['kb_id', 'workspace_id'], 'ai_cb_kb_kb_ws_foreign')
                ->references(['id', 'workspace_id'])->on('ai_knowledge_bases')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_chatbot_knowledge_base', function (Blueprint $table) {
            $table->dropForeign('ai_cb_kb_chatbot_ws_foreign');
            $table->dropForeign('ai_cb_kb_kb_ws_foreign');
        });

        Schema::table('ai_chatbots', function (Blueprint $table) {
            $table->dropUnique('ai_chatbots_id_workspace_unique');
        });

        Schema::table('ai_knowledge_bases', function (Blueprint $table) {
            $table->dropUnique('ai_knowledge_bases_id_workspace_unique');
        });

        Schema::table('ai_chatbot_knowledge_base', function (Blueprint $table) {
            $table->dropIndex(['workspace_id']);
            $table->dropColumn('workspace_id');
        });
    }
};
