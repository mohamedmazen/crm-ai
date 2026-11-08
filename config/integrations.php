<?php

return [

    /*
    |--------------------------------------------------------------------------
    | BYOK-only mode
    |--------------------------------------------------------------------------
    |
    | When enabled, every workspace must supply its own provider credentials.
    | The platform never silently falls back to system-owned AI, SMS, WhatsApp
    | system-user tokens or SMTP for tenant traffic, so a workspace without a
    | key fails with a clear "add your key" error instead of spending the
    | operator's money.
    |
    | Platform-owned services are intentionally excluded and stay required:
    |   - storage (StorageManager, per-plan quota)
    |   - Meta App identity (app_id / app_secret: webhook verification and
    |     Embedded Signup)
    |   - payment gateways (collection of the platform's own subscriptions)
    |
    */

    'byok_only' => env('BYOK_ONLY', false),

];
