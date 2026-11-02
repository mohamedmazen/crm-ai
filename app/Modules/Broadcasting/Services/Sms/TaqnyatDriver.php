<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;

// Provider: Taqnyat (https://www.taqnyat.sa) — Saudi Arabia.
class TaqnyatDriver implements SmsDriverInterface
{
    public function __construct(
        private readonly string $token,
        private readonly string $sender,
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        $resp = Http::withToken($this->token)
            ->timeout(15)
            ->post('https://api.taqnyat.sa/v1/messages', [
                // Recipients must be international format without "+" or leading "00".
                'recipients' => [ltrim($to, '+')],
                'body' => $body,
                'sender' => $opts['from'] ?? $this->sender,
            ]);

        $json = $resp->json() ?? [];
        $messageId = $json['messageId'] ?? '';

        if ($resp->successful() && $messageId !== '') {
            return new SmsSendResult(true, (string) $messageId);
        }

        return new SmsSendResult(false, '', (string) ($json['message'] ?? 'Taqnyat error'));
    }

    public function status(string $providerId): SmsStatus
    {
        // Taqnyat delivery is pushed via the configured webhook.
        return new SmsStatus($providerId, 'sent');
    }
}
