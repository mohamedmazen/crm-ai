<?php

namespace App\Services\Mail;

use App\Models\SmtpConfiguration;
use App\Modules\Broadcasting\Models\WorkspaceSmtpConfig;
use App\Support\Tenancy\Byok;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Resolves the SMTP configuration a tenant's outbound email must use.
 *
 * Order: workspace override → platform mailer. In BYOK-only mode the platform
 * mailer (and the default `mail.default` mailer behind it) is never used for
 * tenant traffic, so a workspace without its own SMTP fails with an actionable
 * error instead of sending from the operator's server.
 *
 * Platform mail (verification, password reset, admin test-send) does not go
 * through here: it must keep using {@see SmtpConfiguration::getActive()}.
 */
class TenantSmtpResolver
{
    public static function resolve(int $workspaceId): ?Model
    {
        $workspace = WorkspaceSmtpConfig::forWorkspace($workspaceId);

        if ($workspace) {
            return $workspace;
        }

        return Byok::only() ? null : SmtpConfiguration::getActive();
    }

    /**
     * Resolve the tenant mailer, or throw when BYOK-only forbids the platform
     * fallback. Returns null when no mailer is configured at all in the legacy
     * (non-BYOK) setup, letting callers keep their `Mail::html()` fallback.
     */
    public static function resolveOrFail(int $workspaceId): ?Model
    {
        $smtp = self::resolve($workspaceId);

        if ($smtp === null && Byok::only()) {
            throw new RuntimeException(
                'No email server configured for this workspace. '.
                'Add your own SMTP credentials under Broadcasts → Email Server.'
            );
        }

        return $smtp;
    }
}
