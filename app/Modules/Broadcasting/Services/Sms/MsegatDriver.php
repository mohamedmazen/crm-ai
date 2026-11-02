<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;

// Provider: Msegat (https://www.msegat.com) — Saudi Arabia.
class MsegatDriver implements SmsDriverInterface
{
    private const SUCCESS_CODES = ['1', 'M0000'];

    public function __construct(
        private readonly string $userName,
        private readonly string $apiKey,
        private readonly string $sender,
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        $resp = Http::asForm()->timeout(15)->post('https://www.msegat.com/gw/sendsms.php', [
            'userName' => $this->userName,
            'apiKey' => $this->apiKey,
            'numbers' => ltrim($to, '+'),
            'userSender' => $opts['from'] ?? $this->sender,
            'msg' => $body,
            'msgEncoding' => 'UTF8',
            'reqBulkId' => '',
            'timeToSend' => 'now',
            'exactTime' => 'now',
            'reqFilter' => '',
        ]);

        $json = $resp->json() ?? [];
        $code = (string) ($json['code'] ?? '');

        if (in_array($code, self::SUCCESS_CODES, true)) {
            return new SmsSendResult(true, (string) ($json['id'] ?? $json['msgId'] ?? $code));
        }

        return new SmsSendResult(false, '', (string) ($json['message'] ?? $json['error'] ?? "Msegat error (code {$code})"));
    }

    public function status(string $providerId): SmsStatus
    {
        // Msegat delivery is pushed via the configured DLR webhook.
        return new SmsStatus($providerId, 'sent');
    }
}
