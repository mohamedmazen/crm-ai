<?php

namespace App\Support\Tenancy;

/**
 * Whether the platform requires tenants to bring their own provider keys.
 *
 * See config/integrations.php. When enabled, tenant traffic must resolve
 * credentials from its workspace; system-level AI / SMS / WhatsApp token /
 * SMTP defaults are skipped so the operator is never billed for a tenant
 * that forgot to connect its own account.
 *
 * Drivers should not read this flag directly — resolve credentials through
 * the CredentialResolver service, which applies the gate in one place.
 */
class Byok
{
    public static function only(): bool
    {
        return (bool) config('integrations.byok_only', false);
    }
}
