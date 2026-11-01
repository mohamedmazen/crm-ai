<?php

namespace App\Modules\Sales\Services\Calls;

use App\Modules\Broadcasting\Services\Sms\SmsDriverManager;
use App\Modules\Sales\Contracts\CallProvider;
use App\Modules\Sales\Models\CallProviderConfig;
use RuntimeException;

/**
 * Builds the telephony driver for a workspace's saved credentials.
 *
 * Mirrors {@see SmsDriverManager}: callers
 * ask for a workspace and get a driver, or null when nothing is connected.
 */
class CallProviderManager
{
    public static function forWorkspace(int $workspaceId): ?CallProvider
    {
        $config = CallProviderConfig::resolveForWorkspace($workspaceId);

        return $config ? static::forConfig($config) : null;
    }

    public static function forConfig(CallProviderConfig $config): CallProvider
    {
        return match ($config->provider) {
            CallProviderConfig::PROVIDER_TWILIO => new TwilioCallProvider($config->credentials ?? [], $config->caller_id),
            CallProviderConfig::PROVIDER_TELNYX => new TelnyxCallProvider($config->credentials ?? [], $config->caller_id),
            default => throw new RuntimeException("Unknown call provider: {$config->provider}"),
        };
    }

    public static function configured(int $workspaceId): bool
    {
        return CallProviderConfig::forWorkspace($workspaceId)->exists();
    }
}
