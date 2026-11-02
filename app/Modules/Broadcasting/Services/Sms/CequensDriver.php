<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;

// Provider: CEQUENS (https://www.cequens.com) — Egypt / Middle East.
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
        $messageId = $json['data']['messageId']
            ?? $json['data']['message_id']
            ?? $json['messageId']
            ?? $json['message_id']
            ?? $json['id']
            ?? '';

        if ($resp->successful() && $messageId !== '') {
            return new SmsSendResult(true, (string) $messageId);
        }

        $error = $json['replyMessage']
            ?? $json['data']['message']
            ?? $json['message']
            ?? 'CEQUENS error';

        return new SmsSendResult(false, '', (string) $error);
    }

    /**
     * Exchange the service username + API key for a short-lived JWT.
     */
    private function accessToken(): string
    {
        $resp = Http::timeout(15)->post('https://apis.cequens.com/auth/v1/tokens/', [
            'apiKey' => $this->apiKey,
            'userName' => $this->userName,
        ]);

        $json = $resp->json() ?? [];

        return (string) ($json['data']['access_token'] ?? $json['access_token'] ?? '');
    }

    public function status(string $providerId): SmsStatus
    {
        // CEQUENS delivery is pushed via the DLR webhook.
        return new SmsStatus($providerId, 'sent');
    }
}
