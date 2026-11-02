<?php

namespace App\Modules\Sales\Services\Calls;

use App\Modules\Sales\Contracts\CallProvider;
use App\Modules\Sales\Models\CallProviderConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * CEQUENS Voice — direct carrier interconnect across Egypt and the Gulf.
 *
 * Verified against the official API reference:
 *  - Sign-in: POST https://apis.cequens.com/auth/v1/tokens/ with {apiKey, userName}
 *    → data.access_token (JWT, cached like the SMS driver).
 *  - Call:    POST https://apis.cequens.com/voice/v1/call with {to, sayText,
 *    statusCallbackEndpoint, isRecorded, recordCallbackURL} → {callId}.
 *
 * CEQUENS plays a text-to-speech message to the lead rather than bridging an
 * agent, so this gateway is the A2P voice/announcement one; use Unifonic or
 * Telnyx when the call must be handed to a human agent.
 */
class CequensCallProvider implements CallProvider
{
    public function __construct(
        private readonly array $credentials,
        private readonly ?string $callerId = null,
    ) {}

    public function name(): string
    {
        return CallProviderConfig::PROVIDER_CEQUENS;
    }

    public function initiate(string $to, string $from, string $callbackUrl, array $options = []): string
    {
        $message = trim((string) ($this->credentials['message'] ?? ''));

        if ($message === '') {
            throw new RuntimeException('CEQUENS needs a message to play before it can place the call.');
        }

        $token = $this->accessToken();

        if ($token === '') {
            throw new RuntimeException('CEQUENS authentication failed.');
        }

        $voice = trim((string) ($this->credentials['voice'] ?? ''));

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(20)
            ->post('https://apis.cequens.com/voice/v1/call', [
                'to' => $to,
                'sayText' => $message,
                'callerVoice' => $voice !== '' ? $voice : 'Zeina',
                'statusCallbackEndpoint' => $callbackUrl,
                'isRecorded' => true,
                'recordCallbackURL' => $callbackUrl,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('CEQUENS rejected the call: '.$this->errorMessage($response->json() ?? [], $response->body()));
        }

        // The v1 response is `{callId: "..."}`; keep a nested path as a fallback
        // for accounts on a slightly different API revision.
        $callId = (string) ($response->json('callId') ?? $response->json('data.callId') ?? '');

        if ($callId === '') {
            throw new RuntimeException('CEQUENS did not return a call id.');
        }

        return $callId;
    }

    public function verifyWebhook(Request $request): bool
    {
        return CallWebhookToken::matches($request);
    }

    public function parseWebhook(Request $request): ?CallUpdate
    {
        // CEQUENS does not publish the status callback schema, so read the
        // documented request fields with the casing variants seen in the wild.
        $payload = $request->input('data');
        $payload = is_array($payload) ? $payload : [];

        $sid = (string) ($this->first($request, $payload, ['callId', 'call_id', 'CallId', 'CallID']) ?? '');

        if ($sid === '') {
            return null;
        }

        return new CallUpdate(
            providerSid: $sid,
            status: $this->mapStatus((string) ($this->first($request, $payload, ['status', 'callStatus', 'Status']) ?? '')),
            durationSecs: $this->duration($request, $payload),
            recordingUrl: $this->recordingUrl($request, $payload),
            fromNumber: $this->stringOrNull($this->first($request, $payload, ['from', 'fromNumber', 'From'])),
            toNumber: $this->stringOrNull($this->first($request, $payload, ['to', 'toNumber', 'To'])),
        );
    }

    private function mapStatus(string $status): ?string
    {
        $status = strtolower(trim($status));

        return match (true) {
            $status === '' => null,
            str_contains($status, 'answer') && ! str_contains($status, 'no answer') && ! str_contains($status, 'not answer') => 'answered',
            str_contains($status, 'no answer'), str_contains($status, 'not answer'), str_contains($status, 'busy') => 'no_answer',
            str_contains($status, 'fail'), str_contains($status, 'reject'), str_contains($status, 'cancel') => 'failed',
            str_contains($status, 'initiat'), str_contains($status, 'queued'), str_contains($status, 'ringing') => 'initiated',
            str_contains($status, 'complete'), str_contains($status, 'end'), str_contains($status, 'hangup') => 'completed',
            default => null,
        };
    }

    private function duration(Request $request, array $payload): ?int
    {
        $duration = $this->first($request, $payload, ['duration', 'durationSeconds', 'callDuration', 'Duration']);

        return is_numeric($duration) ? max(0, (int) $duration) : null;
    }

    private function recordingUrl(Request $request, array $payload): ?string
    {
        return $this->stringOrNull($this->first($request, $payload, ['recordingUrl', 'recording_url', 'recordUrl', 'recordURL', 'RecordingUrl']));
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function first(Request $request, array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            if ($request->has($key)) {
                return $request->input($key);
            }

            if (array_key_exists($key, $payload)) {
                return $payload[$key];
            }
        }

        return null;
    }

    /**
     * Exchange the service username + API key for a short-lived JWT, cached so a
     * burst of calls reuses one sign-in instead of authenticating every time.
     */
    private function accessToken(): string
    {
        $userName = (string) ($this->credentials['username'] ?? '');
        $apiKey = (string) ($this->credentials['api_key'] ?? '');

        if ($userName === '' || $apiKey === '') {
            return '';
        }

        $cacheKey = 'calls.cequens.token.'.hash('sha256', $userName.'|'.$apiKey);

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::timeout(15)->post('https://apis.cequens.com/auth/v1/tokens/', [
            'apiKey' => $apiKey,
            'userName' => $userName,
        ]);

        $json = $response->json() ?? [];
        $token = (string) ($json['data']['access_token'] ?? $json['access_token'] ?? '');

        if ($token !== '') {
            $expiresIn = (int) ($json['data']['expires_in'] ?? 0);
            $ttl = $expiresIn > 0 ? max(60, $expiresIn - 60) : 3000;
            Cache::put($cacheKey, $token, now()->addSeconds($ttl));
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function errorMessage(array $json, string $body): string
    {
        return (string) ($json['replyMessage'] ?? $json['message'] ?? $json['error'] ?? $body);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
