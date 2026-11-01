<?php

namespace App\Modules\Sales\Services\Calls;

use App\Modules\Sales\Contracts\CallProvider;
use App\Modules\Sales\Models\CallProviderConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Twilio Programmable Voice.
 *
 * The call itself is bridged by a TwiML URL (usually a Twilio TwiML Bin that
 * dials the agent's phone). We only create the call and receive status /
 * recording callbacks; the media never touches this server.
 */
class TwilioCallProvider implements CallProvider
{
    public function __construct(
        private readonly array $credentials,
        private readonly ?string $callerId = null,
    ) {}

    public function name(): string
    {
        return CallProviderConfig::PROVIDER_TWILIO;
    }

    public function initiate(string $to, string $from, string $callbackUrl, array $options = []): string
    {
        $accountSid = (string) ($this->credentials['account_sid'] ?? '');
        $authToken = (string) ($this->credentials['auth_token'] ?? '');
        $voiceUrl = (string) ($this->credentials['twiml_url'] ?? '');

        if ($accountSid === '' || $authToken === '') {
            throw new RuntimeException('Twilio credentials are incomplete.');
        }

        if ($voiceUrl === '') {
            throw new RuntimeException('Twilio needs a Voice URL (TwiML) that dials the agent.');
        }

        $response = Http::asForm()
            ->withBasicAuth($accountSid, $authToken)
            ->timeout(20)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Calls.json", [
                'To' => $to,
                'From' => $from,
                'Url' => $voiceUrl,
                'StatusCallback' => $callbackUrl,
                'StatusCallbackEvent' => ['initiated', 'ringing', 'answered', 'completed'],
                'StatusCallbackMethod' => 'POST',
                'RecordingStatusCallback' => $callbackUrl,
                'RecordingStatusCallbackMethod' => 'POST',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Twilio rejected the call: '.($response->json('message') ?? $response->body()));
        }

        $sid = (string) $response->json('sid', '');

        if ($sid === '') {
            throw new RuntimeException('Twilio did not return a call SID.');
        }

        return $sid;
    }

    public function verifyWebhook(Request $request): bool
    {
        $authToken = (string) ($this->credentials['auth_token'] ?? '');
        $signature = (string) $request->header('X-Twilio-Signature', '');

        if ($authToken === '' || $signature === '') {
            return false;
        }

        $data = $request->fullUrl();
        $params = $request->post();
        ksort($params);

        foreach ($params as $key => $value) {
            $data .= $key.(is_array($value) ? implode('', $value) : $value);
        }

        $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));

        return hash_equals($expected, $signature);
    }

    public function parseWebhook(Request $request): ?CallUpdate
    {
        $sid = (string) $request->input('CallSid', '');

        if ($sid === '') {
            return null;
        }

        $recordingUrl = $this->recordingUrl($request);

        return new CallUpdate(
            providerSid: $sid,
            status: $this->mapStatus((string) $request->input('CallStatus', '')),
            durationSecs: $this->duration($request),
            recordingUrl: $recordingUrl,
            fromNumber: $this->stringOrNull($request->input('From')),
            toNumber: $this->stringOrNull($request->input('To')),
        );
    }

    private function mapStatus(string $status): ?string
    {
        return match ($status) {
            'queued', 'initiated' => 'initiated',
            'ringing' => 'ringing',
            'in-progress', 'answered' => 'answered',
            'completed' => 'completed',
            'busy', 'no-answer' => 'no_answer',
            'failed', 'canceled' => 'failed',
            default => null,
        };
    }

    private function duration(Request $request): ?int
    {
        $duration = $request->input('CallDuration');

        return is_numeric($duration) ? max(0, (int) $duration) : null;
    }

    private function recordingUrl(Request $request): ?string
    {
        $url = $request->input('RecordingUrl');

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = trim($url);
        $path = (string) parse_url($url, PHP_URL_PATH);

        // Twilio omits the file extension; the log links straight to the audio.
        return str_contains(basename($path), '.') ? $url : $url.'.mp3';
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
