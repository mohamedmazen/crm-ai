<?php

namespace App\Modules\Leads\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Leads\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadExportController extends Controller
{
    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function export(Request $request): StreamedResponse
    {
        $wid = $this->workspaceId($request);

        $query = Lead::where('workspace_id', $wid)
            ->with([
                'pipeline:id,name',
                'stage:id,name',
                'assignedUser:id,name',
                'tags:id,name',
            ]);

        if ($request->filled('pipeline_id')) {
            $query->where('pipeline_id', $request->integer('pipeline_id'));
        }

        if ($request->filled('stage_id')) {
            $query->where('stage_id', $request->integer('stage_id'));
        }

        if ($request->filled('band')) {
            $query->where('score_band', $request->input('band'));
        }

        if ($request->filled('assigned')) {
            $assigned = $request->input('assigned');
            if ($assigned === 'me') {
                $query->where('assigned_user_id', Auth::id());
            } elseif ($assigned === 'unassigned') {
                $query->whereNull('assigned_user_id');
            } elseif (is_numeric($assigned)) {
                $query->where('assigned_user_id', (int) $assigned);
            }
        }

        if ($request->filled('tag_id')) {
            $tagId = $request->integer('tag_id');
            $query->whereHas('tags', fn ($tq) => $tq->where('lead_tags.id', $tagId));
        }

        if ($request->filled('search')) {
            $term = '%'.$request->input('search').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)
                ->orWhere('phone', 'like', $term)
                ->orWhere('category', 'like', $term));
        }

        $filename = 'leads-export-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility with Arabic & multilingual text
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, [
                'ID',
                'Name',
                'Phone',
                'Email',
                'Category',
                'Rating',
                'Reviews',
                'Score',
                'Score Band',
                'Deal Value',
                'Currency',
                'Win Probability (%)',
                'Status',
                'Pipeline',
                'Stage',
                'Assigned User',
                'Address',
                'Website',
                'Tags',
                'Created At',
            ]);

            $query->chunk(500, function ($leads) use ($handle) {
                foreach ($leads as $lead) {
                    fputcsv($handle, [
                        $lead->id,
                        $lead->name,
                        $lead->phone,
                        $lead->email,
                        $lead->category,
                        $lead->rating,
                        $lead->review_count,
                        $lead->score,
                        $lead->score_band,
                        $lead->deal_value,
                        $lead->currency,
                        $lead->probability,
                        $lead->status,
                        $lead->pipeline?->name,
                        $lead->stage?->name,
                        $lead->assignedUser?->name,
                        $lead->address,
                        $lead->website,
                        $lead->tags->pluck('name')->implode(', '),
                        $lead->created_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}

