<?php

namespace App\Modules\Leads\Services;

use App\Events\LeadPipelineChanged;
use App\Events\LeadQualified;
use App\Events\LeadScoreChanged;
use App\Events\LeadStageChanged;
use App\Modules\Leads\Contracts\LeadScorer;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadPipeline;
use App\Modules\Leads\Models\LeadPipelineStage;
use App\Modules\Leads\Models\LeadStageHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PipelineManager
{
    public function __construct(
        private readonly LeadScorer $scorer,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Get or create default pipeline for a workspace.
     */
    public function defaultPipelineFor(int $workspaceId): LeadPipeline
    {
        $pipeline = LeadPipeline::where('workspace_id', $workspaceId)
            ->where('is_default', true)
            ->first();

        if (! $pipeline) {
            $pipeline = LeadPipeline::where('workspace_id', $workspaceId)
                ->orderBy('sort_order')
                ->first();
        }

        if (! $pipeline) {
            $pipeline = LeadPipeline::create([
                'workspace_id' => $workspaceId,
                'name' => 'Sales Pipeline',
                'is_default' => true,
                'sort_order' => 0,
            ]);
        }

        return $pipeline;
    }

    /**
     * All pipelines for a workspace.
     *
     * @return Collection<int, LeadPipeline>
     */
    public function pipelinesFor(int $workspaceId): Collection
    {
        $pipelines = LeadPipeline::where('workspace_id', $workspaceId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($pipelines->isEmpty()) {
            $default = $this->defaultPipelineFor($workspaceId);
            $pipelines = new Collection([$default]);
        }

        return $pipelines;
    }

    /**
     * Stages for a workspace and pipeline, seeding defaults on first use.
     *
     * @return Collection<int, LeadPipelineStage>
     */
    public function stagesFor(int $workspaceId, ?int $pipelineId = null): Collection
    {
        $pipeline = $pipelineId
            ? LeadPipeline::where('workspace_id', $workspaceId)->find($pipelineId)
            : $this->defaultPipelineFor($workspaceId);

        if (! $pipeline) {
            $pipeline = $this->defaultPipelineFor($workspaceId);
        }

        $stages = LeadPipelineStage::where('workspace_id', $workspaceId)
            ->where('pipeline_id', $pipeline->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($stages->isEmpty()) {
            foreach (LeadPipelineStage::DEFAULTS as $i => $default) {
                LeadPipelineStage::create($default + [
                    'workspace_id' => $workspaceId,
                    'pipeline_id' => $pipeline->id,
                    'position' => $i,
                ]);
            }

            $stages = LeadPipelineStage::where('workspace_id', $workspaceId)
                ->where('pipeline_id', $pipeline->id)
                ->orderBy('position')
                ->orderBy('id')
                ->get();
        }

        $this->adoptUnstagedLeads($workspaceId, $pipeline, $stages->first());

        return $stages;
    }

    private function adoptUnstagedLeads(int $workspaceId, LeadPipeline $pipeline, ?LeadPipelineStage $first): void
    {
        if (! $first) {
            return;
        }

        // Adopt any leads missing pipeline_id into the default pipeline
        Lead::where('workspace_id', $workspaceId)
            ->whereNull('pipeline_id')
            ->update(['pipeline_id' => $pipeline->id]);

        Lead::where('workspace_id', $workspaceId)
            ->where('pipeline_id', $pipeline->id)
            ->whereNull('stage_id')
            ->update([
                'stage_id' => $first->id,
                'stage_changed_at' => now(),
            ]);
    }

    public function firstStage(int $workspaceId, ?int $pipelineId = null): ?LeadPipelineStage
    {
        return $this->stagesFor($workspaceId, $pipelineId)->first();
    }

    /**
     * Move a lead to a stage and slot it at $position within that column.
     * Records stage history, updates won/lost states, close reasons and probabilities.
     */
    public function moveLead(
        Lead $lead,
        LeadPipelineStage $stage,
        int $position,
        ?int $userId = null,
        ?int $closeReasonId = null
    ): Lead {
        $fromStageId = $lead->stage_id;
        $fromPipelineId = $lead->pipeline_id;
        $from = $lead->stage_id ? ($lead->stage->name ?? null) : null;
        $changedStage = (int) $lead->stage_id !== (int) $stage->id;
        $changedPipeline = $stage->pipeline_id && (int) $fromPipelineId !== (int) $stage->pipeline_id;

        DB::transaction(function () use ($lead, $stage, $position, $changedStage, $fromStageId, $userId, $closeReasonId) {
            // Open a gap at the target index in the destination column.
            Lead::where('workspace_id', $lead->workspace_id)
                ->where('stage_id', $stage->id)
                ->where('id', '!=', $lead->id)
                ->where('board_position', '>=', $position)
                ->increment('board_position');

            $lead->stage_id = $stage->id;
            $lead->board_position = $position;
            if ($stage->pipeline_id) {
                $lead->pipeline_id = $stage->pipeline_id;
            }

            if ($changedStage) {
                $lead->stage_changed_at = now();

                // Record stage transition history
                LeadStageHistory::create([
                    'lead_id' => $lead->id,
                    'from_stage_id' => $fromStageId,
                    'to_stage_id' => $stage->id,
                    'user_id' => $userId,
                    'changed_at' => now(),
                ]);

                if ($stage->is_won) {
                    $lead->won_at = now();
                    $lead->lost_at = null;
                    $lead->status = 'converted';
                    $lead->probability = 100;
                    if ($closeReasonId) {
                        $lead->close_reason_id = $closeReasonId;
                    }
                } elseif ($stage->is_lost) {
                    $lead->lost_at = now();
                    $lead->won_at = null;
                    $lead->status = 'lost';
                    $lead->probability = 0;
                    if ($closeReasonId) {
                        $lead->close_reason_id = $closeReasonId;
                    }
                } else {
                    $lead->won_at = null;
                    $lead->lost_at = null;
                    if ($stage->default_probability !== null) {
                        $lead->probability = $stage->default_probability;
                    }
                }
            }

            $lead->save();
        });

        if ($changedPipeline) {
            $fromPipelineName = $fromPipelineId ? LeadPipeline::find($fromPipelineId)?->name : null;
            $toPipelineName = $stage->pipeline?->name ?? LeadPipeline::find($stage->pipeline_id)?->name ?? '';

            $this->activity->pipelineChanged($lead, $fromPipelineName, $toPipelineName);

            LeadPipelineChanged::dispatch(
                (int) $lead->workspace_id,
                (int) $lead->id,
                $lead->contact?->id,
                $fromPipelineName,
                $toPipelineName,
            );
        }

        if ($changedStage) {
            $this->activity->stageChanged($lead, $from, $stage->name);

            LeadStageChanged::dispatch(
                (int) $lead->workspace_id,
                (int) $lead->id,
                $lead->contact?->id,
                $from,
                $stage->name,
                $stage->is_won,
                $stage->is_lost,
            );
        }

        return $lead->refresh();
    }

    /**
     * Rescore a lead and persist the result.
     */
    public function rescore(Lead $lead): bool
    {
        $wasHot = $lead->score_band === 'hot';
        $previousScore = (int) $lead->score;
        $result = $this->scorer->score($lead);

        $lead->update([
            'score' => $result->score,
            'score_band' => $result->band,
            'score_breakdown' => $result->breakdown,
            'scored_at' => now(),
        ]);

        $becameHot = $result->band === 'hot' && ! $wasHot;

        if ($becameHot) {
            $this->activity->qualified($lead, $result->score);

            LeadQualified::dispatch(
                (int) $lead->workspace_id,
                (int) $lead->id,
                $lead->contact?->id,
                $result->score,
                $result->band,
            );
        }

        // Only announce a real movement: threshold automations compare the
        // previous value against their own threshold to fire on the crossing.
        if ($result->score !== $previousScore) {
            LeadScoreChanged::dispatch(
                (int) $lead->workspace_id,
                (int) $lead->id,
                $lead->contact?->id,
                $previousScore,
                $result->score,
                $result->band,
            );
        }

        return $becameHot;
    }

    public function rescoreWorkspace(int $workspaceId): int
    {
        $count = 0;

        Lead::where('workspace_id', $workspaceId)
            ->with('contact:id,lead_id')
            ->chunkById(200, function ($leads) use (&$count) {
                foreach ($leads as $lead) {
                    $this->rescore($lead);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Delete a stage, moving any leads it holds to $fallback.
     */
    public function deleteStage(LeadPipelineStage $stage, LeadPipelineStage $fallback): void
    {
        DB::transaction(function () use ($stage, $fallback) {
            $offset = (int) Lead::where('workspace_id', $fallback->workspace_id)
                ->where('stage_id', $fallback->id)
                ->lockForUpdate()
                ->max('board_position') + 1;

            Lead::where('stage_id', $stage->id)->update([
                'stage_id' => $fallback->id,
                'board_position' => DB::raw('board_position + '.$offset),
            ]);

            $stage->delete();
        });
    }

    /** @param  list<int>  $orderedIds */
    public function reorderStages(int $workspaceId, array $orderedIds): void
    {
        DB::transaction(function () use ($workspaceId, $orderedIds) {
            foreach ($orderedIds as $position => $id) {
                LeadPipelineStage::where('workspace_id', $workspaceId)
                    ->where('id', $id)
                    ->update(['position' => $position]);
            }
        });
    }

    public function nextStagePosition(int $workspaceId, ?int $pipelineId = null): int
    {
        $query = LeadPipelineStage::where('workspace_id', $workspaceId);

        if ($pipelineId) {
            $query->where('pipeline_id', $pipelineId);
        }

        return (int) $query->max('position') + 1;
    }
}
