<?php

namespace App\Modules\Broadcasting\Services\Sms;

use App\Modules\Broadcasting\Models\SmsProviderConfig;
use App\Modules\Integrations\Services\CredentialResolver;
use RuntimeException;

class SmsDriverManager
{
    /** Providers in fallback priority order. */
    private const PRIORITY = [
        'twilio', 'nexmo', 'telnyx', 'clicksend', 'amazon_sns',
        'sms_misr', 'cequens', 'unifonic', 'taqnyat', 'msegat', 'infobip',
    ];

    /**
     * Credential key that carries the sender/from value for each provider. Used to apply
     * the workspace-level "Sender ID" override on top of the stored credentials.
     */
    private const SENDER_KEYS = [
        'twilio' => 'from_number',
        'nexmo' => 'from',
        'telnyx' => 'from',
        'clicksend' => 'from',
        'amazon_sns' => 'sender_id',
        'sms_misr' => 'sender',
        'cequens' => 'sender_name',
        'unifonic' => 'sender_id',
        'taqnyat' => 'sender',
        'msegat' => 'sender',
        'infobip' => 'from',
    ];

    public static function forWorkspace(int $workspaceId): SmsDriverInterface
    {
        // Load every workspace-level configuration once instead of querying per provider.
        $configs = SmsProviderConfig::where('workspace_id', $workspaceId)->get()->keyBy('provider');

        // 1. Workspace-level default
        $default = $configs->firstWhere('default', true);
        if ($default) {
            return static::build($default->provider, static::withSenderOverride($default));
        }

        // 2. Fall back to the first configured provider in priority order: a
        //    workspace override for that provider first, then the system default.
        foreach (self::PRIORITY as $provider) {
            $config = $configs->get($provider);
            if ($config) {
                return static::build($provider, static::withSenderOverride($config));
            }

            $creds = CredentialResolver::system()->sms($provider);
            if ($creds) {
                return static::build($provider, $creds->toArray());
            }
        }

        throw new RuntimeException(
            'No SMS provider configured for workspace '.$workspaceId.
            '. Add your own credentials under Broadcasts → SMS Gateways.'
        );
    }

    /**
     * Merge the workspace-level sender override (if any) into the provider credentials
     * under the provider's native sender key.
     *
     * @return array<string, mixed>
     */
    private static function withSenderOverride(SmsProviderConfig $config): array
    {
        $creds = $config->credentials ?? [];
        $senderId = (string) ($config->sender_id ?? '');

        if ($senderId !== '') {
            $creds[self::SENDER_KEYS[$config->provider] ?? 'from'] = $senderId;
        }

        return $creds;
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
