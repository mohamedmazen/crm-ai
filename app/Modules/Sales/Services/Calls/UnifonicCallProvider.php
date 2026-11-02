<?php

namespace App\Modules\Sales\Services\Calls;

use App\Modules\Sales\Contracts\CallProvider;
use App\Modules\Sales\Models\CallProviderConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Unifonic Voice — Saudi-founded, with voice in KSA and the UAE.
 *
 * Verified against the official API reference:
 *  - Call: POST https://voice.unifonic.com/v1/calls with header `AppsId` and
 *    body {recipient[], callerId, type: "ivr", callRecording, ivr: {say, transfer}}
 *    → {callId, status}.
 *  - Webhook: registered per voice application (POST /v1/providers/webhook) and
 *    posts one payload per call completion with callId, status, duration,
 *    recordingUrl, from, to (see Voice: Monitoring & Logs).
 *
 * The `ivr.transfer` field hands the answered call to an agent number, which is
 * what makes this a click-to-call gateway rather than an announcement.
 */
class UnifonicCallProvider implements CallProvider
{
    public function __construct(
        private readonly array $credentials,
        private readonly ?string $callerId = null,
    ) {}

    public function name(): string
    {
        return CallProviderConfig::PROVIDER_UNIFONIC;
    }

    public function initiate(string $to, string $from, string $callbackUrl, array $options = []): string
    {
        $appSid = (string) ($this->credentials['app_sid'] ?? '');

        if ($appSid === '') {
            throw new RuntimeException('Unifonic credentials are incomplete.');
        }

        $message = trim((string) ($this->credentials['message'] ?? ''));

        if ($message === '') {
            throw new RuntimeException('Unifonic needs a greeting before it can place the call.');
        }

        $ivr = ['say' => $message];

        $agentNumber = trim((string) ($this->credentials['agent_number'] ?? ''));
        if ($agentNumber !== '') {
            $ivr['transfer'] = $agentNumber;
        }

        $response = Http::withHeaders(['AppsId' => $appSid])
            ->acceptJson()
            ->timeout(20)
            ->post('https://voice.unifonic.com/v1/calls', [
                'recipient' => [$to],
                'callerId' => $from,
                'type' => 'ivr',
                'callRecording' => true,
                'ivr' => $ivr,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Unifonic rejected the call: '.($response->json('message') ?? $response->json('errorMessage') ?? $response->body()));
        }

        $callId = (string) ($response->json('callId') ?? '');

        if ($callId === '') {
            throw new RuntimeException('Unifonic did not return a call id.');
        }

        return $callId;
    }

    public function verifyWebhook(Request $request): bool
    {
        return CallWebhookToken::matches($request);
    }

    public function parseWebhook(Request $request): ?CallUpdate
    {
        $sid = (string) ($request->input('callId') ?? $request->input('callSid') ?? '');

        if ($sid === '') {
            return null;
        }

        return new CallUpdate(
            providerSid: $sid,
            status: $this->mapStatus((string) $request->input('status', '')),
            durationSecs: is_numeric($request->input('duration')) ? max(0, (int) $request->input('duration')) : null,
            recordingUrl: $this->stringOrNull($request->input('recordingUrl')),
            fromNumber: $this->stringOrNull($request->input('from')),
            toNumber: $this->stringOrNull($request->input('to')),
        );
    }

    private function mapStatus(string $status): ?string
    {
        $status = strtolower(trim($status));

        return match (true) {
            $status === '' => null,
            // "Completed with Failure" has a positive duration but ends early.
            str_contains($status, 'failure') => 'failed',
            str_contains($status, 'completed') => 'completed',
            str_contains($status, 'no answer'), str_contains($status, 'not answered'), str_contains($status, 'busy') => 'no_answer',
            str_contains($status, 'reject'), str_contains($status, 'cancel'), str_contains($status, 'fail'), str_contains($status, 'unreachable'), str_contains($status, 'terminated') => 'failed',
            str_contains($status, 'queued'), str_contains($status, 'processing') => 'initiated',
            default => null,
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
