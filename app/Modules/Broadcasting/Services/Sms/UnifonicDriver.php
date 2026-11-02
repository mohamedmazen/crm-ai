<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;

// Provider: Unifonic (https://www.unifonic.com) — Egypt / Gulf.
class UnifonicDriver implements SmsDriverInterface
{
    public function __construct(
        private readonly string $appSid,
        private readonly string $senderId = '',
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        $payload = [
            'AppSid' => $this->appSid,
            // Recipient must be international format without "+" or leading "00".
            'Recipient' => ltrim($to, '+'),
            'Body' => $body,
            'responseType' => 'JSON',
        ];

        $sender = $opts['from'] ?? $this->senderId;
        if ($sender !== '') {
            $payload['SenderID'] = $sender;
        }

        $resp = Http::asForm()->timeout(15)->post('https://el.cloud.unifonic.com/rest/SMS/messages', $payload);

        $json = $resp->json() ?? [];
        $messageId = $json['data']['MessageID'] ?? $json['data']['messageId'] ?? '';

        if ($resp->successful() && ($json['success'] ?? false) === true && $messageId !== '') {
            return new SmsSendResult(true, (string) $messageId);
        }

        return new SmsSendResult(false, '', (string) ($json['message'] ?? $json['errorMessage'] ?? 'Unifonic error'));
    }

    public function status(string $providerId): SmsStatus
    {
        // Unifonic delivery is pushed via the DLR webhook.
        return new SmsStatus($providerId, 'sent');
    }
}
