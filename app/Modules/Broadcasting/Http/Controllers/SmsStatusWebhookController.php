<?php

namespace App\Modules\Broadcasting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Services\WebhookIdempotencyService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Handles inbound delivery-status webhooks from all supported SMS providers
 * (Twilio, Nexmo/Vonage, Telnyx and ClickSend).
 *
 * They share one controller; the route {provider} segment determines which
 * signature verification and field parsing logic to use.
 */
class SmsStatusWebhookController extends Controller
{
    public function __construct(private WebhookIdempotencyService $idempotency) {}

    public function handle(Request $request, string $provider): Response
    {
        match ($provider) {
            'twilio' => $this->verifyTwilio($request),
            'nexmo' => $this->verifyNexmo($request),
            'telnyx' => $this->verifyGenericToken($request, 'telnyx'),
            'clicksend' => $this->verifyGenericToken($request, 'clicksend'),
            default => abort(404),
        };

        [$msgId, $status] = match ($provider) {
            'twilio' => [$request->input('MessageSid'), $this->mapTwilioStatus($request->input('MessageStatus'))],
            'nexmo' => [$request->input('messageId'), $this->mapNexmoStatus($request->input('status'))],
            'telnyx' => [$request->input('data.payload.id'), $this->mapTelnyxStatus($request->input('data.payload.to.0.status'))],
            'clicksend' => [$request->input('message_id'), $this->mapClickSendStatus($request->input('status'))],
            default => [null, null],
        };

        if (! $msgId || ! $status) {
            return new Response('OK', 200);
        }

        // Idempotency
        if (! $this->idempotency->isNewEvent("sms_status_{$provider}", "{$msgId}_{$status}")) {
            return new Response('OK', 200);
        }

        $statusMap = [
            'queued' => 'queued',
            'sent' => 'sent',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed' => 'failed',
        ];
        $mapped = $statusMap[$status] ?? null;

        if ($mapped) {
            $recipient = CampaignRecipient::where('provider_message_id', $msgId)->first();
            if ($recipient) {
                // Status priority — never downgrade.
                $priority = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
                $current = $priority[$recipient->status] ?? 0;
                $next = $priority[$mapped] ?? 0;

                if ($next >= $current) {
                    $now = now();
                    $patch = ['status' => $mapped];

                    if ($mapped === 'sent' && ! $recipient->sent_at) {
                        $patch['sent_at'] = $now;
                    }
                    if ($mapped === 'delivered') {
                        if (! $recipient->sent_at) {
                            $patch['sent_at'] = $now;
                        }
                        if (! $recipient->delivered_at) {
                            $patch['delivered_at'] = $now;
                        }
                    }
                    if ($mapped === 'read') {
                        if (! $recipient->sent_at) {
                            $patch['sent_at'] = $now;
                        }
                        if (! $recipient->delivered_at) {
                            $patch['delivered_at'] = $now;
                        }
                        if (! $recipient->read_at) {
                            $patch['read_at'] = $now;
                        }
                    }
                    if ($mapped === 'failed') {
                        $providerError = $request->input('ErrorMessage')
                            ?? $request->input('error_message')
                            ?? $request->input('errorCode')
                            ?? $request->input('Reason')
                            ?? 'failed';
                        $patch['failed_reason'] = substr((string) $providerError, 0, 512);
                    }

                    $recipient->update($patch);
                }
            }
            Log::info("SMS status update [{$provider}]", ['msg_id' => $msgId, 'status' => $mapped]);
        }

        return new Response('OK', 200);
    }

    private function verifyNexmo(Request $request): void
    {
        $secret = config('services.nexmo.api_secret') ?? config('services.vonage.api_secret') ?? null;
        if (! $secret) {
            return;
        }

        // Vonage DLR: signature is provided in "sig" param (HMAC-MD5 of sorted params)
        $sig = $request->input('sig', '');
        if (! $sig) {
            // No signature present — allow in non-production
            if (app()->environment('production')) {
                abort(401, 'Missing Vonage signature');
            }

            return;
        }

        $params = $request->all();
        unset($params['sig']);
        ksort($params);
        $message = '&'.urldecode(http_build_query($params, '', '&'));
        $expected = md5($message.$secret);

        abort_unless(hash_equals($expected, strtolower($sig)), 401, 'Invalid Vonage signature');
    }

    private function verifyTwilio(Request $request): void
    {
        // Twilio sends X-Twilio-Signature; verify if configured
        $authToken = config('services.twilio.auth_token') ?? null;
        if (! $authToken) {
            return;
        }

        $url = $request->fullUrl();
        $params = $request->all();
        ksort($params);
        $data = $url.implode('', array_map(fn ($k, $v) => $k.$v, array_keys($params), $params));
        $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));
        $received = $request->header('X-Twilio-Signature', '');

        abort_unless(hash_equals($expected, $received), 401, 'Invalid Twilio signature');
    }

    private function mapTwilioStatus(string $s): string
    {
        return match ($s) {
            'sent', 'sending' => 'sent',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed', 'undelivered' => 'failed',
            default => 'queued',
        };
    }

    private function mapNexmoStatus(string $s): string
    {
        return match ($s) {
            'delivered' => 'delivered',
            'failed', 'expired', 'rejected', 'unknown', 'buffered' => 'failed',
            'accepted' => 'sent',
            default => 'queued',
        };
    }

    private function mapTelnyxStatus(?string $s): string
    {
        return match (strtolower((string) $s)) {
            'delivered' => 'delivered',
            'sent', 'sending' => 'sent',
            'delivery_failed', 'sending_failed', 'expired' => 'failed',
            default => 'queued',
        };
    }

    private function mapClickSendStatus(?string $s): string
    {
        $s = strtolower((string) $s);

        return match (true) {
            str_contains($s, 'delivered') => 'delivered',
            str_contains($s, 'bounce') || str_contains($s, 'undeliv') || str_contains($s, 'fail') || str_contains($s, 'reject') => 'failed',
            str_contains($s, 'sent') || str_contains($s, 'submit') => 'sent',
            default => 'queued',
        };
    }

    private function verifyGenericToken(Request $request, string $key): void
    {
        $secret = config("services.{$key}.webhook_secret");
        if (! $secret) {
            return;
        }
        $token = $request->input('token') ?? $request->header('X-Webhook-Token', '');
        abort_unless(hash_equals($secret, $token), 401, 'Invalid webhook token');
    }
}
