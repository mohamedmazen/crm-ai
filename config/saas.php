<?php

return [

    /*
    |--------------------------------------------------------------------------
    | App branding
    |--------------------------------------------------------------------------
    | Used by LandingLayout and email templates.
    | Override these values in config/app.php (app.name) or via .env APP_NAME.
    */
    'app_name' => env('APP_NAME', 'App'),
    // Arabic brand name and tagline. App\Support\Brand resolves these per
    // request locale and falls back to the English values when unset.
    'app_name_ar' => env('SAAS_APP_NAME_AR', env('APP_NAME', 'App')),
    'tagline' => env('SAAS_TAGLINE', 'Your Business. Smarter.'),
    'tagline_ar' => env('SAAS_TAGLINE_AR', 'أعمالك، بذكاء أكبر.'),
    'support_email' => env('SAAS_SUPPORT_EMAIL', env('MAIL_FROM_ADDRESS', 'support@example.com')),
    // External help/documentation URL shown in the client "Help & Docs" nav
    // item. Leave blank to hide the link. Configure in the admin panel or .env.
    'docs_url' => env('SAAS_DOCS_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Marketing / Landing page
    |--------------------------------------------------------------------------
    */
    'marketing' => [
        'nav' => [
            ['label' => 'Features', 'href' => '#features'],
            ['label' => 'Pricing', 'href' => '/pricing'],
        ],
        'footer_links' => [
            ['label' => 'Privacy', 'href' => '/privacy'],
            ['label' => 'Terms', 'href' => '/terms'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Branding defaults (overridden per-Client in Phase 5)
    |--------------------------------------------------------------------------
    */
    'branding' => [
        'primary_color' => env('SAAS_PRIMARY_COLOR', '#F9A61A'),
        'secondary_color' => env('SAAS_SECONDARY_COLOR', '#B07436'),
        'logo_path' => env('SAAS_LOGO_PATH', null),

        // Key is either a fonts.bunny.net slug or a face shipped with the app
        // (`bdo-grotesk`, self-hosted from public/fonts). The value is the CSS
        // family name. Anything not in this list is rejected on save, so a
        // stored value can be interpolated into a stylesheet URL/declaration.
        'font_family' => env('SAAS_FONT_FAMILY', 'bdo-grotesk'),
        'fonts' => [
            'bdo-grotesk' => 'BDO Grotesk',
            'inter' => 'Inter',
            'dm-sans' => 'DM Sans',
            'plus-jakarta-sans' => 'Plus Jakarta Sans',
            'figtree' => 'Figtree',
            'manrope' => 'Manrope',
            'outfit' => 'Outfit',
            'poppins' => 'Poppins',
            'montserrat' => 'Montserrat',
            'work-sans' => 'Work Sans',
            'nunito' => 'Nunito',
            'rubik' => 'Rubik',
            'karla' => 'Karla',
            'mulish' => 'Mulish',
            'raleway' => 'Raleway',
            'lato' => 'Lato',
            'open-sans' => 'Open Sans',
            'roboto' => 'Roboto',
            'source-sans-3' => 'Source Sans 3',
            'ibm-plex-sans' => 'IBM Plex Sans',
        ],
    ],

];
