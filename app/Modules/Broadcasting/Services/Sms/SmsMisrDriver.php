<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;

// Provider: SMS Misr (https://smsmisr.com) — Egypt.
class SmsMisrDriver implements SmsDriverInterface
{
    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $sender,
        private readonly string $environment = '1',
        private readonly string $language = '2',
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        // environment: 1 = live, 2 = sandbox. language: 1 = English, 2 = Unicode (Arabic).
        $resp = Http::asForm()->timeout(15)->post('https://smsmisr.com/api/SMS/', [
            'environment' => $this->environment,
            'username' => $this->username,
            'password' => $this->password,
            'sender' => $opts['from'] ?? $this->sender,
            'mobile' => ltrim($to, '+'),
            'language' => $this->language,
            'message' => $body,
        ]);

        $json = $resp->json() ?? [];
        $code = (string) ($json['code'] ?? '');

        // SMS Misr signals acceptance with code "1901".
        if ($resp->successful() && $code === '1901') {
            return new SmsSendResult(true, (string) ($json['SMSID'] ?? ''));
        }

        return new SmsSendResult(false, '', (string) ($json['message'] ?? $json['error'] ?? "SMS Misr error (code {$code})"));
    }

    public function status(string $providerId): SmsStatus
    {
        // SMS Misr reports delivery through its own report tooling, not a pull API.
        return new SmsStatus($providerId, 'sent');
    }
}
