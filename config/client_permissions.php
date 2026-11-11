<?php

/**
 * Client-side role permissions.
 *
 * The admin panel has its own Role/Permission tables; client users historically
 * had three hardcoded roles and no per-module control at all. This catalogue is
 * the single source of truth for what a client role can do:
 *
 *  - `catalog` maps a module to the route-name prefixes it owns, so the
 *    middleware can turn a request into a `module.action` permission.
 *  - `system_roles` are the three built-in roles. They are never stored in the
 *    database, which keeps existing users working without a backfill.
 *  - `templates` are one-click presets the client can turn into a custom role.
 *
 * Keep prefixes longest-first in mind: the resolver sorts them, so
 * `client.settings.gdpr` wins over `client.settings.`.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    |
    | `write` marks modules that have no state-changing routes (the audit log),
    | so the UI hides the pointless "can edit" toggle for them. `admin_only`
    | marks modules whose controllers still require a client administrator
    | (team, audit log, privacy); they stay in the catalogue so the built-in
    | administrator role gets them, but custom roles cannot be granted them.
    |
    */
    'catalog' => [
        'inbox' => [
            'area' => 'inbox',
            'prefixes' => ['client.inbox.'],
            'write' => true,
        ],
        'contacts' => [
            'area' => 'contacts',
            'prefixes' => ['client.contacts.', 'client.segments.', 'client.custom-fields.'],
            'write' => true,
        ],
        'leads' => [
            'area' => 'sales',
            'prefixes' => ['client.leads.', 'client.tasks.'],
            'write' => true,
        ],
        'quotes' => [
            'area' => 'sales',
            'prefixes' => ['client.sales.quotes.', 'client.sales.products.'],
            'write' => true,
        ],
        'invoices' => [
            'area' => 'sales',
            'prefixes' => ['client.sales.invoices.'],
            'write' => true,
        ],
        'bookings' => [
            'area' => 'sales',
            'prefixes' => ['client.sales.booking.', 'client.sales.forms.', 'client.sales.lead-sources.'],
            'write' => true,
        ],
        'calls' => [
            'area' => 'sales',
            'prefixes' => ['client.sales.calls.'],
            'write' => true,
        ],
        'sequences' => [
            'area' => 'sales',
            'prefixes' => ['client.sales.sequences.'],
            'write' => true,
        ],
        'ai_sdr' => [
            'area' => 'sales',
            'prefixes' => ['client.sales.ai-sdr.'],
            'write' => true,
        ],
        'ecommerce' => [
            'area' => 'sales',
            'prefixes' => ['client.ecommerce.'],
            'write' => true,
        ],
        'campaigns' => [
            'area' => 'marketing',
            'prefixes' => ['client.campaigns.'],
            'write' => true,
        ],
        'whatsapp_marketing' => [
            'area' => 'marketing',
            'prefixes' => ['client.whatsapp.'],
            'write' => true,
        ],
        'sms' => [
            'area' => 'marketing',
            'prefixes' => ['client.sms-gateways.'],
            'write' => true,
        ],
        'email' => [
            'area' => 'marketing',
            'prefixes' => ['client.email-server.'],
            'write' => true,
        ],
        'social' => [
            'area' => 'marketing',
            'prefixes' => ['client.social.'],
            'write' => true,
        ],
        'automations' => [
            'area' => 'automation',
            'prefixes' => ['client.automations.'],
            'write' => true,
        ],
        'reports' => [
            'area' => 'reports',
            'prefixes' => ['client.reports.', 'reports.exports.'],
            'write' => true,
        ],
        'ai' => [
            'area' => 'settings',
            'prefixes' => ['client.ai.'],
            'write' => true,
        ],
        'settings' => [
            'area' => 'settings',
            'prefixes' => [
                'client.media.',
                'client.api-tokens.',
                'client.api-docs',
                'client.webhooks.',
                'client.workspaces.',
                'client.support.',
            ],
            'write' => true,
        ],
        'billing' => [
            'area' => 'settings',
            'prefixes' => ['client.billing.', 'client.subscription.', 'client.pricing', 'client.coupon.', 'client.checkout.'],
            'write' => true,
        ],
        'team' => [
            'area' => 'settings',
            'prefixes' => ['client.team.', 'client.invitations.', 'client.roles.'],
            'write' => true,
            'admin_only' => true,
        ],
        'audit' => [
            'area' => 'settings',
            'prefixes' => ['client.audit-log.'],
            'write' => false,
            'admin_only' => true,
        ],
        'privacy' => [
            'area' => 'settings',
            'prefixes' => ['client.settings.gdpr'],
            'write' => true,
            'admin_only' => true,
        ],
        'data_export' => [
            'area' => 'settings',
            'prefixes' => ['client.settings.data-export'],
            'write' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Areas (matrix grouping in the UI)
    |--------------------------------------------------------------------------
    */
    'areas' => [
        'inbox' => 'nav.group_inbox',
        'contacts' => 'nav.group_contacts',
        'sales' => 'nav.group_sales',
        'marketing' => 'nav.area_marketing',
        'automation' => 'nav.group_automations',
        'reports' => 'nav.group_reports',
        'settings' => 'nav.settings',
    ],

    /*
    |--------------------------------------------------------------------------
    | Always-allowed routes
    |--------------------------------------------------------------------------
    |
    | Self-service surface every authenticated client user needs, regardless of
    | role: their dashboard, own profile, notifications and workspace switcher.
    | Matched by exact route name before any prefix is considered.
    |
    */
    'public_routes' => [
        'client.dashboard',
        'client.search',
        'client.onboarding.show',
        'client.onboarding.complete',
        'client.profile.edit',
        'client.profile.update',
        'client.profile.destroy',
        'client.profile.2fa',
        'client.profile.2fa.enable',
        'client.profile.2fa.disable',
        'client.profile.2fa.recovery-codes',
        'client.profile.sessions',
        'client.profile.sessions.destroy',
        'client.notifications.index',
        'client.notifications.recent',
        'client.notifications.unread-count',
        'client.notifications.read',
        'client.notifications.read-all',
        'client.notifications.destroy',
        'client.notification-preferences.update',
        'client.push.subscribe',
        'client.push.unsubscribe',
        'client.settings.index',
        'client.settings.update',
        'client.settings.notifications',
        'client.workspaces.switch',
        'client.subscription.invoice',
    ],

    /*
    |--------------------------------------------------------------------------
    | Built-in roles
    |--------------------------------------------------------------------------
    |
    | `wildcard` grants every catalogue permission (minus `deny`). `read_only`
    | grants every `*.view` (minus `deny`). Roles are identified by the value
    | stored in `users.client_role`, so these slugs must never change.
    |
    */
    'system_roles' => [
        'administrator' => [
            'label' => 'admin.administrator',
            'wildcard' => true,
            'deny' => [],
        ],
        'staff' => [
            'label' => 'admin.staff',
            'wildcard' => true,
            'deny' => ['team.view', 'team.write', 'audit.view', 'privacy.view', 'privacy.write'],
        ],
        'viewer' => [
            'label' => 'admin.viewer',
            'read_only' => true,
            'deny' => ['team.view', 'team.write', 'audit.view', 'privacy.view', 'privacy.write'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Role templates
    |--------------------------------------------------------------------------
    |
    | Presets offered when the client creates a custom role. They are copied
    | into a normal role row, so editing one never changes the template.
    |
    */
    'templates' => [
        'supervisor' => [
            'label' => 'permissions.templates.supervisor',
            'permissions' => [
                '*',
            ],
            'deny' => ['billing.view', 'billing.write', 'privacy.view', 'privacy.write', 'audit.view'],
        ],
        'sales_agent' => [
            'label' => 'permissions.templates.sales_agent',
            'permissions' => [
                'inbox.view', 'inbox.write',
                'contacts.view', 'contacts.write',
                'leads.view', 'leads.write',
                'quotes.view', 'quotes.write',
                'invoices.view',
                'bookings.view', 'bookings.write',
                'calls.view', 'calls.write',
                'sequences.view', 'sequences.write',
                'reports.view',
                'settings.view', 'settings.write',
            ],
        ],
        'support_agent' => [
            'label' => 'permissions.templates.support_agent',
            'permissions' => [
                'inbox.view', 'inbox.write',
                'contacts.view', 'contacts.write',
                'leads.view',
                'bookings.view',
                'settings.view', 'settings.write',
            ],
        ],
        'marketer' => [
            'label' => 'permissions.templates.marketer',
            'permissions' => [
                'contacts.view',
                'campaigns.view', 'campaigns.write',
                'whatsapp_marketing.view', 'whatsapp_marketing.write',
                'sms.view', 'sms.write',
                'email.view', 'email.write',
                'social.view', 'social.write',
                'automations.view', 'automations.write',
                'reports.view',
                'settings.view', 'settings.write',
            ],
        ],
        'accountant' => [
            'label' => 'permissions.templates.accountant',
            'permissions' => [
                'invoices.view', 'invoices.write',
                'quotes.view',
                'reports.view',
                'billing.view',
                'data_export.view', 'data_export.write',
            ],
        ],
    ],

];
