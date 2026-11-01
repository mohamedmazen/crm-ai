<?php

namespace App\Modules\Sales\Services\Calls;

use App\Events\CallCompleted;
use App\Events\CallInitiated;
use App\Events\CallRecordingStored;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Exceptions\CallNotPossible;
use App\Modules\Sales\Models\CallLog;
use App\Modules\Sales\Models\CallProviderConfig;
use RuntimeException;

/**
 * Places calls and applies provider lifecycle updates to the call log.
 *
 * Provider signatures are verified in the webhook controller; this service only
 * persists an already-trusted {@see CallUpdate}, so it can also be driven by a
 * future automation action or an import without an HTTP request.
 */
class CallService
{
    public function initiate(int $workspaceId, ?int $userId, Lead $lead, ?string $fromNumber = null): CallLog
    {
        $config = CallProviderConfig::resolveForWorkspace($workspaceId);

        if (! $config) {
            throw new CallNotPossible(
                __('Connect a call provider in Call settings before placing calls.'),
                CallNotPossible::NO_PROVIDER,
            );
        }

        $to = trim((string) $lead->phone);

        if ($to === '') {
            throw new CallNotPossible(
                __('This lead has no phone number.'),
                CallNotPossible::NO_RECIPIENT,
            );
        }

        $from = trim((string) ($fromNumber ?: $config->caller_id));

        if ($from === '') {
            throw new CallNotPossible(
                __('Set the caller ID in Call settings before placing calls.'),
                CallNotPossible::NO_PROVIDER,
            );
        }

        $callbackUrl = route('webhooks.calls.status', [
            'provider' => $config->provider,
            'config' => $config->id,
        ]);

        try {
            $providerSid = CallProviderManager::forConfig($config)->initiate($to, $from, $callbackUrl);
        } catch (RuntimeException $e) {
            throw new CallNotPossible($e->getMessage(), CallNotPossible::PROVIDER_FAILED);
        }

        $call = CallLog::create([
            'workspace_id' => $workspaceId,
            'lead_id' => $lead->id,
            'user_id' => $userId,
            'direction' => CallLog::DIRECTION_OUTBOUND,
            'from_number' => $from,
            'to_number' => $to,
            'status' => CallLog::STATUS_INITIATED,
            'provider' => $config->provider,
            'provider_sid' => $providerSid,
        ]);

        CallInitiated::dispatch(
            $workspaceId,
            (int) $call->id,
            (int) $lead->id,
            $userId,
            CallLog::DIRECTION_OUTBOUND,
            $providerSid,
        );

        return $call;
    }

    /**
     * Apply a verified provider update. Returns null when the sid is unknown so a
     * webhook for a call we never logged (another workspace's traffic) is a no-op.
     */
    public function recordUpdate(CallProviderConfig $config, CallUpdate $update): ?CallLog
    {
        // Scope by workspace as well as sid: a provider sid is only meaningful to
        // the workspace whose credentials signed the callback.
        $call = CallLog::where('workspace_id', $config->workspace_id)
            ->where('provider_sid', $update->providerSid)
            ->first();

        if (! $call) {
            return null;
        }

        $wasTerminal = $call->isTerminal();
        $previousRecording = $call->recording_url;

        if ($update->status !== null && in_array($update->status, CallLog::STATUSES, true)) {
            $call->status = $update->status;
        }

        if ($update->durationSecs !== null) {
            $call->duration_secs = $update->durationSecs;
        }

        if ($update->recordingUrl !== null) {
            $call->recording_url = $update->recordingUrl;
        }

        if ($update->fromNumber !== null) {
            $call->from_number = $update->fromNumber;
        }

        if ($update->toNumber !== null) {
            $call->to_number = $update->toNumber;
        }

        $call->save();

        if (! $wasTerminal && $call->isTerminal()) {
            CallCompleted::dispatch(
                (int) $call->workspace_id,
                (int) $call->id,
                $call->lead_id !== null ? (int) $call->lead_id : null,
                (string) $call->status,
                (int) $call->duration_secs,
            );
        }

        if ($call->recording_url !== null && $call->recording_url !== $previousRecording) {
            CallRecordingStored::dispatch(
                (int) $call->workspace_id,
                (int) $call->id,
                $call->lead_id !== null ? (int) $call->lead_id : null,
                (string) $call->recording_url,
            );
        }

        return $call;
    }

    /**
     * Log a call that happened outside the app (manual entry / import).
     *
     * @param  array<string, mixed>  $attributes  already-validated call_log attributes
     */
    public function recordManual(int $workspaceId, ?int $userId, array $attributes): CallLog
    {
        $call = CallLog::create([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'duration_secs' => 0,
            ...$attributes,
        ]);

        if ($call->isTerminal()) {
            CallCompleted::dispatch(
                $workspaceId,
                (int) $call->id,
                $call->lead_id !== null ? (int) $call->lead_id : null,
                (string) $call->status,
                (int) $call->duration_secs,
            );
        }

        return $call;
    }
}
