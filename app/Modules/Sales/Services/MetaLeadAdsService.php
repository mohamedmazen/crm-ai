<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\LeadSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaLeadAdsService
{
    public function __construct(
        private readonly LeadAutoCaptureService $autoCaptureService,
    ) {}

    /**
     * Process inbound Meta Lead Ads webhook payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, mixed>
     */
    public function processWebhook(array $payload, ?string $token = null): array
    {
        $source = null;
        if ($token) {
            $source = LeadSource::where('webhook_token', $token)->first();
        }

        $capturedLeads = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            $pageId = (string) ($entry['id'] ?? '');

            // Fallback source search by page_id if no token
            if (! $source && $pageId) {
                $source = LeadSource::where('type', LeadSource::TYPE_META_ADS)
                    ->where('config->page_id', $pageId)
                    ->first();
            }

            if (! $source) {
                // If still no source, fallback to the first active Meta Ads source or skip
                $source = LeadSource::where('type', LeadSource::TYPE_META_ADS)->where('status', LeadSource::STATUS_ACTIVE)->first();
                if (! $source) {
                    continue;
                }
            }

            $workspaceId = $source->workspace_id;

            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? '') !== 'leadgen') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $leadgenId = (string) ($value['leadgen_id'] ?? '');
                $formId = (string) ($value['form_id'] ?? '');
                $adId = (string) ($value['ad_id'] ?? '');

                $leadData = [
                    'leadgen_id' => $leadgenId,
                    'form_id' => $formId,
                    'ad_id' => $adId,
                ];

                // If test data or direct field_data provided in payload
                if (! empty($value['field_data'])) {
                    foreach ($value['field_data'] as $field) {
                        $name = $field['name'] ?? '';
                        $val = $field['values'][0] ?? null;
                        if ($name && $val !== null) {
                            $leadData[$name] = $val;
                        }
                    }
                } elseif (! empty($value['lead_data']) && is_array($value['lead_data'])) {
                    $leadData = array_merge($leadData, $value['lead_data']);
                } elseif ($leadgenId && ! empty($source->config['page_access_token'])) {
                    // Call Meta Graph API to fetch full lead submission
                    try {
                        $graphResponse = Http::timeout(10)->get("https://graph.facebook.com/v21.0/{$leadgenId}", [
                            'access_token' => $source->config['page_access_token'],
                        ]);

                        if ($graphResponse->successful()) {
                            $apiData = $graphResponse->json();
                            foreach ($apiData['field_data'] ?? [] as $field) {
                                $fieldName = $field['name'] ?? '';
                                $fieldVal = $field['values'][0] ?? null;
                                if ($fieldName && $fieldVal !== null) {
                                    $leadData[$fieldName] = $fieldVal;
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::error('Failed to fetch Meta lead details from Graph API', [
                            'leadgen_id' => $leadgenId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $lead = $this->autoCaptureService->captureFromMetaLead($workspaceId, $leadData, $source);
                $capturedLeads[] = $lead;
            }
        }

        return $capturedLeads;
    }
}
