<?php

namespace App\Modules\Broadcasting\Services\Sms;

use App\Models\Workspace;
use App\Modules\Broadcasting\Models\SmsProviderConfig;
use App\Modules\Integrations\Services\CredentialResolver;
use RuntimeException;

class SmsDriverManager
{
    public static function forWorkspace(int $workspaceId): SmsDriverInterface
    {
        // 1. Workspace-level default
        $config = SmsProviderConfig::where('workspace_id', $workspaceId)
            ->where('default', true)
            ->first();

        if ($config) {
            return static::build($config->provider, $config->credentials ?? []);
        }

        // 2. Fall back to first configured system default (in priority order)
        $workspace = app(Workspace::class)->find($workspaceId);
        foreach ([
            'twilio', 'nexmo', 'telnyx', 'clicksend', 'amazon_sns',
            'sms_misr', 'cequens', 'unifonic', 'taqnyat', 'msegat', 'infobip',
        ] as $provider) {
            $creds = CredentialResolver::for($workspace)->sms($provider);
            if ($creds) {
                return static::build($provider, $creds->toArray());
            }
        }

        throw new RuntimeException('No SMS provider configured for workspace '.$workspaceId);
    }

    public static function build(string $provider, array $creds): SmsDriverInterface
    {
        return match ($provider) {
            'twilio' => new TwilioDriver($creds['account_sid'] ?? '', $creds['auth_token'] ?? '', $creds['from_number'] ?? ''),
            'nexmo' => new NexmoDriver($creds['api_key'] ?? '', $creds['api_secret'] ?? '', $creds['from'] ?? ''),
            'telnyx' => new TelnyxDriver($creds['api_key'] ?? '', $creds['from'] ?? '', $creds['messaging_profile_id'] ?? ''),
            'clicksend' => new ClickSendDriver($creds['username'] ?? '', $creds['api_key'] ?? '', $creds['from'] ?? ''),
            'amazon_sns' => new AmazonSnsDriver($creds['access_key'] ?? '', $creds['secret_key'] ?? '', $creds['region'] ?? 'us-east-1', $creds['sender_id'] ?? '', $creds['sms_type'] ?? 'Transactional'),
            'sms_misr' => new SmsMisrDriver($creds['username'] ?? '', $creds['password'] ?? '', $creds['sender'] ?? '', $creds['environment'] ?? '1', $creds['language'] ?? '2'),
            'cequens' => new CequensDriver($creds['username'] ?? '', $creds['api_key'] ?? '', $creds['sender_name'] ?? ''),
            'unifonic' => new UnifonicDriver($creds['app_sid'] ?? '', $creds['sender_id'] ?? ''),
            'taqnyat' => new TaqnyatDriver($creds['token'] ?? '', $creds['sender'] ?? ''),
            'msegat' => new MsegatDriver($creds['username'] ?? '', $creds['api_key'] ?? '', $creds['sender'] ?? ''),
            'infobip' => new InfobipDriver($creds['api_key'] ?? '', $creds['base_url'] ?? '', $creds['from'] ?? ''),
            default => throw new RuntimeException("Unknown SMS provider: {$provider}"),
        };
    }
}
