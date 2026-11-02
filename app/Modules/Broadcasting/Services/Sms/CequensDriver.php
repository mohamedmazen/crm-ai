<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Provider: CEQUENS (https://www.cequens.com) — Egypt / Middle East.
 *
 * Verified against the official API reference:
 *  - Sign-in: POST https://apis.cequens.com/auth/v1/tokens/ with {apiKey, userName}
 *    → data.access_token (JWT).
 *  - Send:    POST https://apis.cequens.com/sms/v1/messages with
 *    {senderName, messageText, messageType, recipients[]}.
 *    → {replyCode: 0, data: {SentSMSIDs: [{SMSId: "..."}]}}.
 *  - DLR:     pushed to the configured webhook as query params msgid + statusid
 *    (1 = Delivered, 2 = UnDelivered).
 */
class CequensDriver implements SmsDriverInterface
{
    public function __construct(
        private readonly string $userName,
        private readonly string $apiKey,
        private readonly string $senderName,
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        $token = $this->accessToken();

        if ($token === '') {
            return new SmsSendResult(false, '', 'CEQUENS authentication failed');
        }

        $resp = Http::withToken($token)
            ->timeout(15)
            ->post('https://apis.cequens.com/sms/v1/messages', [
                'senderName' => $opts['from'] ?? $this->senderName,
                'messageText' => $body,
                'messageType' => 'text',
                'recipients' => [ltrim($to, '+')],
            ]);

        $json = $resp->json() ?? [];
        // The v1 response nests the id at data.SentSMSIDs[0].SMSId; keep the
        // older/flat paths as fallbacks for accounts on a different API revision.
        $messageId = $json['data']['SentSMSIDs'][0]['SMSId']
            ?? $json['data']['messageId']
            ?? $json['data']['message_id']
            ?? $json['messageId']
            ?? $json['message_id']
            ?? $json['id']
            ?? '';

        if ($resp->successful() && $messageId !== '') {
            return new SmsSendResult(true, (string) $messageId);
        }

        $error = $json['error']['internalErrors'][0]['details']
            ?? $json['replyMessage']
            ?? $json['data']['message']
            ?? $json['message']
            ?? 'CEQUENS error';

        return new SmsSendResult(false, '', (string) $error);
    }

    /**
     * Exchange the service username + API key for a short-lived JWT. The token is cached
     * so a burst of sends reuses one sign-in instead of authenticating on every message.
     */
    private function accessToken(): string
    {
        $cacheKey = 'sms.cequens.token.'.hash('sha256', $this->userName.'|'.$this->apiKey);

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $resp = Http::timeout(15)->post('https://apis.cequens.com/auth/v1/tokens/', [
            'apiKey' => $this->apiKey,
            'userName' => $this->userName,
        ]);

        $json = $resp->json() ?? [];
        $token = (string) ($json['data']['access_token'] ?? $json['access_token'] ?? '');

        if ($token !== '') {
            // Honour the reported TTL when present, otherwise assume the typical hour.
            $expiresIn = (int) ($json['data']['expires_in'] ?? 0);
            $ttl = $expiresIn > 0 ? max(60, $expiresIn - 60) : 3000;
            Cache::put($cacheKey, $token, now()->addSeconds($ttl));
        }

        return $token;
    }

    public function status(string $providerId): SmsStatus
    {
        // CEQUENS delivery is pushed via the DLR webhook.
        return new SmsStatus($providerId, 'sent');
    }
}
