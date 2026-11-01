<?php

namespace App\Modules\Sales\Services\Calls;

use App\Modules\Sales\Contracts\CallProvider;
use App\Modules\Sales\Models\CallProviderConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Telnyx Call Control v2.
 *
 * Telnyx signs webhooks with Ed25519. The workspace pastes the public key from
 * its Telnyx portal; we verify the raw body with sodium before trusting it.
 */
class TelnyxCallProvider implements CallProvider
{
    /** Reject replayed callbacks older than this many seconds. */
    private const SIGNATURE_TOLERANCE = 300;

    public function __construct(
        private readonly array $credentials,
        private readonly ?string $callerId = null,
    ) {}

    public function name(): string
    {
        return CallProviderConfig::PROVIDER_TELNYX;
    }

    public function initiate(string $to, string $from, string $callbackUrl, array $options = []): string
    {
        $apiKey = (string) ($this->credentials['api_key'] ?? '');
        $connectionId = (string) ($this->credentials['connection_id'] ?? '');

        if ($apiKey === '' || $connectionId === '') {
            throw new RuntimeException('Telnyx credentials are incomplete.');
        }

        $response = Http::withToken($apiKey)
            ->timeout(20)
            ->post('https://api.telnyx.com/v2/calls', [
                'connection_id' => $connectionId,
                'to' => $to,
                'from' => $from,
                'webhook_url' => $callbackUrl,
                'webhook_url_method' => 'POST',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Telnyx rejected the call: '.($response->json('errors.0.detail') ?? $response->body()));
        }

        $callControlId = (string) $response->json('data.call_control_id', '');

        if ($callControlId === '') {
            throw new RuntimeException('Telnyx did not return a call control id.');
        }

        return $callControlId;
    }

    public function verifyWebhook(Request $request): bool
    {
        $publicKey = (string) ($this->credentials['public_key'] ?? '');
        $signature = (string) $request->header('telnyx-signature-ed25519', '');
        $timestamp = (string) $request->header('telnyx-timestamp', '');

        if ($publicKey === '' || $signature === '' || $timestamp === '') {
            return false;
        }

        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        $timestamp = $this->normaliseTimestamp($timestamp);

        if ($timestamp === null || abs(now()->timestamp - $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        $decodedKey = base64_decode($publicKey, true);
        $decodedSignature = base64_decode($signature, true);

        if ($decodedKey === false || $decodedSignature === false) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached(
                $decodedSignature,
                $timestamp.'|'.$request->getContent(),
                $decodedKey,
            );
        } catch (\Throwable) {
            return false;
        }
    }

    public function parseWebhook(Request $request): ?CallUpdate
    {
        $payload = $request->input('payload', []);
        $data = $request->input('data', $payload);

        if (! is_array($data)) {
            return null;
        }

        $sid = (string) ($data['call_control_id'] ?? $payload['call_control_id'] ?? '');

        if ($sid === '') {
            return null;
        }

        $eventType = (string) ($data['event_type'] ?? $request->input('event_type', ''));

        return new CallUpdate(
            providerSid: $sid,
            status: $this->mapStatus($eventType, $payload),
            durationSecs: $this->duration($payload),
            recordingUrl: $this->recordingUrl($payload),
            fromNumber: $this->stringOrNull($payload['from'] ?? $data['from'] ?? null),
            toNumber: $this->stringOrNull($payload['to'] ?? $data['to'] ?? null),
        );
    }

    private function mapStatus(string $eventType, array $payload): ?string
    {
        return match ($eventType) {
            'call.initiated' => 'initiated',
            'call.ringing' => 'ringing',
            'call.answered', 'call.bridged' => 'answered',
            'call.hangup' => $this->hangupStatus((string) ($payload['hangup_cause'] ?? '')),
            // Recording-only events must not move the call's status.
            'call.recording.saved' => null,
            default => null,
        };
    }

    private function hangupStatus(string $cause): string
    {
        return match ($cause) {
            'timeout', 'user_busy', 'call_rejected', 'rejected' => 'no_answer',
            'originator_cancel' => 'failed',
            default => 'completed',
        };
    }

    private function duration(array $payload): ?int
    {
        if (isset($payload['call_duration_secs']) && is_numeric($payload['call_duration_secs'])) {
            return max(0, (int) $payload['call_duration_secs']);
        }

        $start = $payload['start_time'] ?? null;
        $end = $payload['end_time'] ?? null;

        if (! is_string($start) || ! is_string($end)) {
            return null;
        }

        try {
            return max(0, (int) Carbon::parse($start)->diffInSeconds(Carbon::parse($end)));
        } catch (\Throwable) {
            return null;
        }
    }

    private function recordingUrl(array $payload): ?string
    {
        $urls = $payload['recording_urls'] ?? null;

        if (is_array($urls)) {
            $url = $urls['mp3'] ?? $urls[0] ?? null;

            return $this->stringOrNull($url);
        }

        return $this->stringOrNull($payload['recording_url'] ?? null);
    }

    private function normaliseTimestamp(string $timestamp): ?int
    {
        if (! ctype_digit($timestamp)) {
            return null;
        }

        $value = (int) $timestamp;

        // Telnyx sends seconds; tolerate milliseconds if a future version does.
        return $value > 99999999999 ? intdiv($value, 1000) : $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
