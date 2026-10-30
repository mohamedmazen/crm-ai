<?php

namespace App\Modules\Leads\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadPipelineStage;
use App\Modules\Leads\Models\LeadTag;
use App\Modules\Leads\Services\ActivityRecorder;
use App\Modules\Leads\Services\LeadAssignmentService;
use App\Modules\Leads\Services\PipelineManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LeadImportController extends Controller
{
    public function __construct(
        private readonly PipelineManager $pipelineManager,
        private readonly LeadAssignmentService $assignment,
        private readonly ActivityRecorder $activity,
    ) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function import(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'pipeline_id' => ['nullable', 'integer'],
            'stage_id' => ['nullable', 'integer'],
            'tag_names' => ['nullable', 'array', 'max:10'],
            'tag_names.*' => ['string', 'max:64'],
        ]);

        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');

        $firstLine = fgets($handle) ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($firstLine, $d))->first();
        rewind($handle);

        $rawHeaders = null;
        $rows = [];
        $limit = 5000;

        while (($line = fgetcsv($handle, null, $delimiter)) !== false && count($rows) < $limit) {
            if ($rawHeaders === null) {
                $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $line[0]);
                $rawHeaders = array_map(fn ($h) => str_replace([' ', '-'], '_', strtolower(trim((string) $h))), $line);

                continue;
            }

            $line = array_pad(array_slice($line, 0, count($rawHeaders)), count($rawHeaders), null);
            $rows[] = array_combine($rawHeaders, $line);
        }
        fclose($handle);

        if ($rawHeaders === null || empty($rows)) {
            return back()->withErrors(['file' => __('The CSV file appears to be empty or has no valid rows.')]);
        }

        // Resolve default pipeline and first stage if not supplied
        $pipelineId = $request->integer('pipeline_id') ?: $this->pipelineManager->defaultPipelineFor($wid)->id;
        $stageId = $request->integer('stage_id') ?: $this->pipelineManager->firstStage($wid, $pipelineId)?->id;

        // Tags to attach
        $tagIds = [];
        if (! empty($request->input('tag_names'))) {
            $tagIds = collect($request->input('tag_names'))
                ->filter()
                ->map(fn ($name) => LeadTag::firstOrCreate(['workspace_id' => $wid, 'name' => trim($name)])->id)
                ->all();
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $mapped = $this->mapRow($row);

            if (empty($mapped['name']) && empty($mapped['phone']) && empty($mapped['email'])) {
                $skipped++;

                continue;
            }

            // Deduplication by phone or email in this workspace
            $existing = null;
            if (! empty($mapped['phone'])) {
                $existing = Lead::where('workspace_id', $wid)->where('phone', $mapped['phone'])->first();
            }
            if (! $existing && ! empty($mapped['email'])) {
                $existing = Lead::where('workspace_id', $wid)->where('email', $mapped['email'])->first();
            }

            if ($existing) {
                $existing->update(array_filter([
                    'name' => $mapped['name'] ?? $existing->name,
                    'phone' => $mapped['phone'] ?? $existing->phone,
                    'email' => $mapped['email'] ?? $existing->email,
                    'category' => $mapped['category'] ?? $existing->category,
                    'website' => $mapped['website'] ?? $existing->website,
                    'address' => $mapped['address'] ?? $existing->address,
                    'city' => $mapped['city'] ?? $existing->city,
                    'country' => $mapped['country'] ?? $existing->country,
                    'deal_value' => $mapped['deal_value'] ?? $existing->deal_value,
                ], fn ($v) => $v !== null));

                if (! empty($tagIds)) {
                    $existing->tags()->syncWithoutDetaching($tagIds);
                }

                $updated++;
            } else {
                $lead = Lead::create([
                    'workspace_id' => $wid,
                    'pipeline_id' => $pipelineId,
                    'stage_id' => $stageId,
                    'name' => $mapped['name'] ?? 'Unnamed Lead',
                    'phone' => $mapped['phone'] ?? null,
                    'email' => $mapped['email'] ?? null,
                    'category' => $mapped['category'] ?? null,
                    'website' => $mapped['website'] ?? null,
                    'address' => $mapped['address'] ?? null,
                    'city' => $mapped['city'] ?? null,
                    'country' => $mapped['country'] ?? null,
                    'deal_value' => $mapped['deal_value'] ?? null,
                    'status' => 'new',
                ]);

                if (! empty($tagIds)) {
                    $lead->tags()->syncWithoutDetaching($tagIds);
                }

                // Rescore and auto-assign if configured
                $this->pipelineManager->rescore($lead);
                $this->assignment->autoAssign($lead);
                $this->activity->created($lead, 'csv_import');

                $created++;
            }
        }

        return back()->with('success', __("Import complete: :created created, :updated updated, :skipped skipped.", [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ]));
    }

    private function mapRow(array $row): array
    {
        $get = function (array $aliases) use ($row) {
            foreach ($aliases as $alias) {
                if (isset($row[$alias]) && trim((string) $row[$alias]) !== '') {
                    return trim((string) $row[$alias]);
                }
            }

            return null;
        };

        return [
            'name' => $get(['name', 'full_name', 'business_name', 'company', 'lead_name', 'title']),
            'phone' => $get(['phone', 'mobile', 'telephone', 'tel', 'phone_number', 'cell']),
            'email' => $get(['email', 'e-mail', 'email_address']),
            'category' => $get(['category', 'industry', 'type']),
            'website' => $get(['website', 'url', 'site']),
            'address' => $get(['address', 'location', 'street']),
            'city' => $get(['city', 'town']),
            'country' => $get(['country']),
            'deal_value' => is_numeric($get(['deal_value', 'value', 'amount'])) ? (float) $get(['deal_value', 'value', 'amount']) : null,
        ];
    }
}

