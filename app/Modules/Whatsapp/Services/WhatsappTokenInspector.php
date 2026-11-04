<?php

namespace App\Modules\Whatsapp\Services;

use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Integrations\Services\Credentials\MetaCredentials;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use Illuminate\Support\Carbon;

/**
 * Reads token expiry/permissions from Meta's /debug_token endpoint.
 *
 * Requires the platform Meta App credentials (an app access token is the only
 * token allowed to inspect another token). When they are missing the account
 * keeps a null expiry, which the UI treats as "unknown" rather than unhealthy.
 */
class WhatsappTokenInspector
{
    /**
     * @return array{valid: bool, expires_at: int, scopes: list<string>, error: string|null}|null
     */
    public function inspect(WhatsappBusinessAccount $waba): ?array
    {
        $token = $waba->accessToken();
        $meta = CredentialResolver::system()->meta();

        if (! $token || ! $this->hasAppCredentials($meta)) {
            return null;
        }

        return CloudApiClient::inspectToken($token, $this->appToken($meta));
    }

    public function expiresAt(string $accessToken, ?MetaCredentials $meta): ?Carbon
    {
        if (! $this->hasAppCredentials($meta)) {
            return null;
        }

        $result = CloudApiClient::inspectToken($accessToken, $this->appToken($meta));

        if ($result === null) {
            return null;
        }

        return WhatsappConnectionHealth::expiresAtFromMeta($result['expires_at']);
    }

    private function hasAppCredentials(?MetaCredentials $meta): bool
    {
        return $meta !== null && $meta->appId() !== null && $meta->appSecret() !== null;
    }

    private function appToken(MetaCredentials $meta): string
    {
        return $meta->appId().'|'.$meta->appSecret();
    }
}
