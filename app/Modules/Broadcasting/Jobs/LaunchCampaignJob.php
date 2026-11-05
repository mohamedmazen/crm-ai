<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Segment;
use App\Modules\Shared\Services\ContactService;
use App\Modules\Shared\Services\SegmentResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LaunchCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $campaignId) {}

    public function handle(): void
    {
        $campaign = Campaign::find($this->campaignId);
        if (! $campaign || $campaign->status !== 'queued') {
            return;
        }

        // Fairness: a workspace may only keep a couple of campaigns in flight.
        // The job goes back onto the queue instead of occupying a broadcast
        // worker while every other tenant waits.
        $maxActive = (int) config('queue.tenant_limits.max_active_campaigns', 2);
        if ($maxActive > 0 && $this->activeCampaignCount($campaign) >= $maxActive) {
            $this->release(60);

            return;
        }

        $campaign->update(['status' => 'sending']);

        // Materialise recipients in pages so a million-contact audience never
        // lives in PHP memory.
        $this->materializeRecipients($campaign);

        $totalRecipients = CampaignRecipient::where('campaign_id', $campaign->id)->count();

        if ($totalRecipients === 0) {
            $campaign->update([
                'status' => 'failed',
                'totals_json' => array_merge($campaign->totals_json ?? [], [
                    'total' => 0,
                    'failed_reason' => 'No matching contacts for audience.',
                ]),
            ]);

            Log::channel('json')->warning('campaign.launch.empty_audience', [
                'workspace_id' => $campaign->workspace_id,
                'campaign_id' => $campaign->id,
                'audience_type' => $campaign->audience_type,
                'audience_ref' => $campaign->audience_ref,
            ]);

            return;
        }

        $queuedRecipients = CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'queued')
            ->count();

        if ($queuedRecipients === 0) {
            // Every recipient was already processed by an earlier run; let the
            // finalizer settle the campaign status.
            FinalizeCampaignJob::dispatch($campaign->id)->onQueue('broadcast');

            return;
        }

        // Progressive dispatch: exactly one chunk is in flight per campaign, and
        // each chunk chains the next one after its own pacing window.
        DispatchCampaignChunkJob::dispatch($campaign->id)->onQueue('broadcast');

        // Independent safety net: polls every 60s until every recipient settles.
        // It self-reschedules, so a throttled multi-hour campaign stays tracked.
        FinalizeCampaignJob::dispatch($campaign->id)
            ->onQueue('broadcast')
            ->delay(now()->addSeconds(60));
    }

    /**
     * How many other campaigns this workspace already has sending.
     */
    private function activeCampaignCount(Campaign $campaign): int
    {
        return Campaign::where('workspace_id', $campaign->workspace_id)
            ->where('status', 'sending')
            ->whereKeyNot($campaign->id)
            ->count();
    }

    /**
     * Insert a campaign_recipient row for every eligible contact, page by page.
     */
    private function materializeRecipients(Campaign $campaign): void
    {
        if ($campaign->audience_type === 'csv') {
            foreach (array_chunk($this->resolveCsv($campaign), 1000) as $contactIds) {
                $this->insertRecipients($campaign, $this->eligibleContactIds($campaign, $contactIds));
            }

            return;
        }

        $query = $this->audienceQuery($campaign);

        if ($query === null) {
            return;
        }

        $query->select('id')->chunkById(1000, function ($contacts) use ($campaign) {
            $this->insertRecipients($campaign, $contacts->pluck('id')->all());
        });
    }

    /**
     * @param  array<int, int|string>  $contactIds
     */
    private function insertRecipients(Campaign $campaign, array $contactIds): void
    {
        if ($contactIds === []) {
            return;
        }

        $now = now();

        $rows = array_map(fn ($contactId) => [
            'campaign_id' => $campaign->id,
            'contact_id' => (int) $contactId,
            'status' => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
        ], $contactIds);

        // insertOrIgnore is required so re-launching a paused campaign
        // doesn't violate the unique (campaign_id, contact_id) index.
        CampaignRecipient::insertOrIgnore($rows);
    }

    /**
     * Query of contacts matching the campaign's audience, before channel filters.
     */
    private function audienceQuery(Campaign $campaign): ?Builder
    {
        $query = match ($campaign->audience_type) {
            'segment' => $this->segmentAudienceQuery($campaign),
            'tag' => $this->tagAudienceQuery($campaign),
            'contact_list' => Contact::query(),
            default => null,
        };

        if ($query === null) {
            return null;
        }

        return $this->applyChannelFilters(
            $query->where('workspace_id', $campaign->workspace_id),
            $campaign,
        );
    }

    private function segmentAudienceQuery(Campaign $campaign): ?Builder
    {
        $segment = Segment::where('workspace_id', $campaign->workspace_id)
            ->find($campaign->audience_ref);

        return $segment
            ? app(SegmentResolver::class)->query($segment)
            : null;
    }

    private function tagAudienceQuery(Campaign $campaign): ?Builder
    {
        if (! $campaign->audience_ref) {
            return null;
        }

        return Contact::whereHas(
            'tags',
            fn ($q) => $q->where('contact_tags.id', $campaign->audience_ref),
        );
    }

    private function applyChannelFilters(Builder $query, Campaign $campaign): Builder
    {
        // Never blast contacts who opted out of the campaign's channel.
        $optInColumn = match ($campaign->channel) {
            'whatsapp' => 'opt_in_whatsapp',
            'sms' => 'opt_in_sms',
            'email' => 'opt_in_email',
            default => null,
        };

        if ($optInColumn !== null) {
            $query->where($optInColumn, true);
        }

        // Email additionally requires a non-empty email; SMS/WhatsApp require a phone.
        if ($campaign->channel === 'email') {
            $query->whereNotNull('email')->where('email', '!=', '');
        } else {
            $query->whereNotNull('phone_e164')->where('phone_e164', '!=', '');
        }

        return $query;
    }

    /**
     * Re-check a CSV batch against the same channel filters used for queries.
     *
     * @param  array<int, int|string>  $contactIds
     * @return array<int, int>
     */
    private function eligibleContactIds(Campaign $campaign, array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }

        return $this->applyChannelFilters(
            Contact::where('workspace_id', $campaign->workspace_id)->whereIn('id', $contactIds),
            $campaign,
        )->pluck('id')->all();
    }

    /**
     * Read the CSV at `audience_ref` from the default storage disk and upsert contacts.
     * Expects a header row with at least `phone_e164` or `email`.
     *
     * @return array<int, int>
     */
    private function resolveCsv(Campaign $campaign): array
    {
        $path = $campaign->audience_ref;

        // Reject paths that could escape the expected storage area.
        // realpath-style normalisation isn't available before the file is read,
        // so we block directory-traversal sequences and absolute paths explicitly.
        if (
            $path &&
            (str_contains($path, '..') || str_starts_with($path, '/') || str_starts_with($path, '\\'))
        ) {
            Log::channel('json')->warning('campaign.csv.invalid_path', [
                'campaign_id' => $campaign->id,
                'path' => $path,
            ]);

            return [];
        }

        if (! $path || ! Storage::exists($path)) {
            Log::channel('json')->warning('campaign.csv.missing', [
                'campaign_id' => $campaign->id,
                'path' => $path,
            ]);

            return [];
        }

        $rows = [];
        $handle = fopen(Storage::path($path), 'r');
        if (! $handle) {
            return [];
        }

        $header = null;
        try {
            while (($line = fgetcsv($handle)) !== false) {
                if ($header === null) {
                    $header = array_map(fn ($h) => trim(strtolower((string) $h)), $line);

                    continue;
                }
                $rows[] = array_combine(
                    $header,
                    array_pad($line, count($header), null),
                );
            }
        } finally {
            fclose($handle);
        }

        if (empty($rows)) {
            return [];
        }

        $service = app(ContactService::class);
        $contactIds = [];
        foreach ($rows as $row) {
            try {
                $contact = $service->upsert($campaign->workspace_id, [
                    'phone_e164' => $row['phone_e164'] ?? $row['phone'] ?? null,
                    'email' => $row['email'] ?? null,
                    'first_name' => $row['first_name'] ?? null,
                    'last_name' => $row['last_name'] ?? null,
                    'country' => $row['country'] ?? null,
                    'language' => $row['language'] ?? null,
                    'opt_in_whatsapp' => $this->coerceBool($row['opt_in_whatsapp'] ?? $row['opt_in_wa'] ?? true),
                    'opt_in_sms' => $this->coerceBool($row['opt_in_sms'] ?? true),
                    'opt_in_email' => $this->coerceBool($row['opt_in_email'] ?? true),
                    'source' => 'campaign_csv',
                ]);
                $contactIds[] = $contact->id;
            } catch (\Throwable $e) {
                Log::channel('json')->info('campaign.csv.row_failed', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return array_values(array_unique($contactIds));
    }

    private function coerceBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'y', 'on'], true);
    }
}
