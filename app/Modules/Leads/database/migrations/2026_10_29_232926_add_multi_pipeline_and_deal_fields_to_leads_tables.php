<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create lead_pipelines table
        Schema::create('lead_pipelines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name', 128);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('workspace_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // 2. Create lead_close_reasons table
        Schema::create('lead_close_reasons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->enum('type', ['won', 'lost']);
            $table->string('name', 128);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'type']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        // 3. Add pipeline columns to lead_pipeline_stages
        Schema::table('lead_pipeline_stages', function (Blueprint $table) {
            $table->unsignedBigInteger('pipeline_id')->nullable()->after('workspace_id');
            $table->unsignedTinyInteger('default_probability')->nullable()->after('is_lost');
            $table->index('pipeline_id');
        });

        // 4. Add deal economics, pipeline and status columns to leads
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('pipeline_id')->nullable()->after('workspace_id');
            $table->enum('status', ['new', 'contacted', 'qualified', 'converted', 'lost'])->default('new')->after('stage_changed_at');
            $table->decimal('deal_value', 14, 2)->nullable()->after('status');
            $table->string('currency', 8)->nullable()->after('deal_value');
            $table->unsignedTinyInteger('probability')->nullable()->after('currency');
            $table->date('expected_close_date')->nullable()->after('probability');
            $table->timestamp('won_at')->nullable()->after('expected_close_date');
            $table->timestamp('lost_at')->nullable()->after('won_at');
            $table->unsignedBigInteger('close_reason_id')->nullable()->after('lost_at');

            $table->index(['workspace_id', 'pipeline_id']);
            $table->index(['workspace_id', 'status']);
        });

        // 5. Create lead_stage_history table
        Schema::create('lead_stage_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('from_stage_id')->nullable();
            $table->unsignedBigInteger('to_stage_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->index(['lead_id', 'changed_at']);
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
            $table->foreign('from_stage_id')->references('id')->on('lead_pipeline_stages')->nullOnDelete();
            $table->foreign('to_stage_id')->references('id')->on('lead_pipeline_stages')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        // 6. Backfill existing workspaces with default pipeline and link stages/leads
        $workspaces = DB::table('workspaces')->select('id')->get();
        $now = now();

        foreach ($workspaces as $workspace) {
            $pipelineId = DB::table('lead_pipelines')->insertGetId([
                'workspace_id' => $workspace->id,
                'name' => 'Sales Pipeline',
                'is_default' => true,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Link existing stages in this workspace to the default pipeline
            DB::table('lead_pipeline_stages')
                ->where('workspace_id', $workspace->id)
                ->whereNull('pipeline_id')
                ->update(['pipeline_id' => $pipelineId]);

            // Link existing leads in this workspace to the default pipeline
            DB::table('leads')
                ->where('workspace_id', $workspace->id)
                ->whereNull('pipeline_id')
                ->update(['pipeline_id' => $pipelineId]);

            // Seed default close reasons
            $defaultReasons = [
                ['workspace_id' => $workspace->id, 'type' => 'won', 'name' => 'Price competitive', 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
                ['workspace_id' => $workspace->id, 'type' => 'won', 'name' => 'Better feature fit', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['workspace_id' => $workspace->id, 'type' => 'won', 'name' => 'Referral / Reputation', 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
                ['workspace_id' => $workspace->id, 'type' => 'lost', 'name' => 'Lost to competitor', 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
                ['workspace_id' => $workspace->id, 'type' => 'lost', 'name' => 'Budget constraints', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['workspace_id' => $workspace->id, 'type' => 'lost', 'name' => 'No response / Cold', 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
                ['workspace_id' => $workspace->id, 'type' => 'lost', 'name' => 'Feature missing', 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ];
            DB::table('lead_close_reasons')->insert($defaultReasons);
        }

        // Backfill lead_stage_history for existing leads that have a stage
        $existingLeads = DB::table('leads')
            ->whereNotNull('stage_id')
            ->select('id', 'stage_id', 'stage_changed_at', 'created_at')
            ->get();

        $historyRows = [];
        foreach ($existingLeads as $lead) {
            $historyRows[] = [
                'lead_id' => $lead->id,
                'from_stage_id' => null,
                'to_stage_id' => $lead->stage_id,
                'user_id' => null,
                'changed_at' => $lead->stage_changed_at ?? $lead->created_at ?? $now,
            ];
        }

        if (! empty($historyRows)) {
            foreach (array_chunk($historyRows, 500) as $chunk) {
                DB::table('lead_stage_history')->insert($chunk);
            }
        }

        // 7. Add foreign keys now that data is backfilled
        Schema::table('lead_pipeline_stages', function (Blueprint $table) {
            $table->foreign('pipeline_id')->references('id')->on('lead_pipelines')->cascadeOnDelete();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreign('pipeline_id')->references('id')->on('lead_pipelines')->nullOnDelete();
            $table->foreign('close_reason_id')->references('id')->on('lead_close_reasons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['pipeline_id']);
            $table->dropForeign(['close_reason_id']);
            $table->dropIndex(['workspace_id', 'pipeline_id']);
            $table->dropIndex(['workspace_id', 'status']);
            $table->dropColumn([
                'pipeline_id',
                'status',
                'deal_value',
                'currency',
                'probability',
                'expected_close_date',
                'won_at',
                'lost_at',
                'close_reason_id',
            ]);
        });

        Schema::table('lead_pipeline_stages', function (Blueprint $table) {
            $table->dropForeign(['pipeline_id']);
            $table->dropIndex(['pipeline_id']);
            $table->dropColumn(['pipeline_id', 'default_probability']);
        });

        Schema::dropIfExists('lead_stage_history');
        Schema::dropIfExists('lead_close_reasons');
        Schema::dropIfExists('lead_pipelines');
    }
};
