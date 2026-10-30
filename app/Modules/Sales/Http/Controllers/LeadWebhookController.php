<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Leads\Services\PipelineManager;
use App\Modules\Sales\Models\LeadSource;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LeadWebhookController extends Controller
{
    public function __construct(
        private readonly PipelineManager $pipelineManager,
    ) {}

    /**
     * Ingest an inbound lead from a webhook.
     */
    public function receive(Request $request, string $token): JsonResponse
    {
        $source = LeadSource::where('webhook_token', $token)->first();

        if (! $source) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid webhook token',
            ], 404);
        }

        if ($source->status !== LeadSource::STATUS_ACTIVE) {
            return response()->json([
                'success' => false,
                'error' => 'Lead source is not active',
            ], 403);
        }

        $payload = $request->input('data') ?? $request->input('lead') ?? $request->all();

        $name = trim((string) ($payload['name'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));

        if ($name === '' && $email === '' && $phone === '') {
            return response()->json([
                'success' => false,
                'error' => 'Validation error',
                'message' => 'At least name, email, or phone is required.',
            ], 422);
        }

        if ($name === '') {
            if ($email !== '') {
                $name = Str::headline(Str::before($email, '@'));
            } elseif ($phone !== '') {
                $name = 'Lead ' . $phone;
            } else {
                $name = 'Webhook Lead #' . rand(1000, 9999);
            }
        }

        try {
            $lead = DB::transaction(function () use ($source, $payload, $name, $email, $phone, $request) {
                $config = $source->config ?? [];

                // Determine pipeline & stage
                $pipelineId = ! empty($payload['pipeline_id']) ? (int) $payload['pipeline_id'] : ($config['pipeline_id'] ?? null);
                if (! $pipelineId) {
                    $defaultPipe = $this->pipelineManager->defaultPipelineFor($source->workspace_id);
                    $pipelineId = $defaultPipe->id;
                }

                $stageId = ! empty($payload['stage_id']) ? (int) $payload['stage_id'] : ($config['stage_id'] ?? null);
                if (! $stageId) {
                    $firstStage = $this->pipelineManager->firstStage($source->workspace_id, $pipelineId);
                    $stageId = $firstStage->id;
                }

                $lead = Lead::create([
                    'workspace_id' => $source->workspace_id,
                    'pipeline_id' => $pipelineId,
                    'stage_id' => $stageId,
                    'lead_source_id' => $source->id,
                    'source' => $source->name,
                    'name' => $name,
                    'email' => $email !== '' ? $email : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'website' => ! empty($payload['website']) ? (string) $payload['website'] : null,
                    'address' => ! empty($payload['address']) ? (string) $payload['address'] : null,
                    'city' => ! empty($payload['city']) ? (string) $payload['city'] : null,
                    'country' => ! empty($payload['country']) ? (string) $payload['country'] : null,
                    'deal_value' => ! empty($payload['deal_value']) ? (float) $payload['deal_value'] : null,
                    'utm_source' => $payload['utm_source'] ?? $request->query('utm_source'),
                    'utm_medium' => $payload['utm_medium'] ?? $request->query('utm_medium'),
                    'utm_campaign' => $payload['utm_campaign'] ?? $request->query('utm_campaign'),
                    'utm_term' => $payload['utm_term'] ?? $request->query('utm_term'),
                    'utm_content' => $payload['utm_content'] ?? $request->query('utm_content'),
                    'referrer' => $payload['referrer'] ?? $request->header('referer'),
                    'landing_page' => $payload['landing_page'] ?? null,
                ]);

                // Create activity for notes/message if present
                $message = $payload['message'] ?? $payload['notes'] ?? null;
                if (! empty($message)) {
                    LeadActivity::create([
                        'workspace_id' => $source->workspace_id,
                        'lead_id' => $lead->id,
                        'user_id' => null,
                        'type' => 'note',
                        'body' => 'Message via webhook: ' . $message,
                        'meta' => [
                            'source' => 'webhook',
                            'lead_source_id' => $source->id,
                        ],
                        'occurred_at' => now(),
                    ]);
                }

                $source->update([
                    'last_lead_at' => now(),
                    'last_error' => null,
                ]);

                return $lead;
            });

            return response()->json([
                'success' => true,
                'lead_id' => $lead->id,
                'message' => 'Lead received and processed successfully',
            ], 201);
        } catch (Exception $e) {
            $source->update([
                'last_error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to process lead: ' . $e->getMessage(),
            ], 500);
        }
    }
}

