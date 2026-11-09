<?php

namespace App\Support;

/**
 * Central builder for Meta Graph API URLs and the active Graph API version.
 *
 * Meta deprecates Graph API versions on a fixed schedule, and a single hardcoded
 * version was previously spread across the WhatsApp, Inbox, Sales and Social
 * modules. Bump META_GRAPH_VERSION in .env to move every call at once.
 */
final class MetaGraph
{
    /** Fallback used when config is unavailable (e.g. during first-run install). */
    public const DEFAULT_VERSION = 'v27.0';

    /** Active Graph API version, e.g. "v27.0". */
    public static function version(): string
    {
        $version = (string) config('services.meta.graph_version', self::DEFAULT_VERSION);

        return $version !== '' ? $version : self::DEFAULT_VERSION;
    }

    /** Base URL for Graph API calls, e.g. "https://graph.facebook.com/v27.0". */
    public static function base(): string
    {
        return 'https://graph.facebook.com/'.self::version();
    }

    /** Full Graph API URL for a path, e.g. url('me/accounts'). */
    public static function url(string $path = ''): string
    {
        $path = ltrim($path, '/');

        return $path === '' ? self::base() : self::base().'/'.$path;
    }

    /**
     * Facebook OAuth dialog host with the active version,
     * e.g. "https://www.facebook.com/v27.0".
     */
    public static function dialog(): string
    {
        return 'https://www.facebook.com/'.self::version();
    }
}
