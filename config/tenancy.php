<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant Domains
    |--------------------------------------------------------------------------
    |
    | Workspaces can be reached on `<subdomain>.<base_domain>` or on their own
    | custom domain. Point a wildcard DNS record (`*.base_domain`) at the
    | application and issue a wildcard certificate; the middleware resolves the
    | workspace from the Host header. Leave `base_domain` empty to serve a
    | single-tenant installation where only the central host is valid.
    |
    */

    'enabled' => (bool) env('TENANCY_ENABLED', true),

    'base_domain' => env('TENANCY_BASE_DOMAIN'),

    /*
    | Hosts that always resolve to the platform itself (marketing site, login,
    | admin panel). Comma-separated; the APP_URL host is added automatically.
    */
    'central_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TENANCY_CENTRAL_DOMAINS', 'localhost,127.0.0.1')),
    ))),

    /*
    | Subdomains that may never be claimed by a workspace, either because they
    | are platform services or because they would shadow infrastructure.
    */
    'reserved_subdomains' => [
        'www', 'app', 'admin', 'administrator', 'api', 'mail', 'smtp', 'imap',
        'pop', 'webmail', 'cdn', 'static', 'assets', 'docs', 'status',
        'support', 'help', 'billing', 'login', 'portal', 'auth', 'sso',
        'webhook', 'webhooks', 'test', 'staging', 'dev',
    ],

];
