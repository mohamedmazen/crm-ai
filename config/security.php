<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Password Breach Check
    |--------------------------------------------------------------------------
    |
    | When enabled, new passwords are checked against the Have I Been Pwned
    | range API using k-anonymity (only the first five characters of the SHA-1
    | hash leave the server). Lookups fail open so a network hiccup never
    | blocks a password change.
    |
    */

    'breach_check' => (bool) env('PASSWORD_BREACH_CHECK', true),

    /*
    |--------------------------------------------------------------------------
    | Sending Domain Verification
    |--------------------------------------------------------------------------
    |
    | When enabled, every outbound message performs a (cached) SPF/DKIM lookup
    | and logs a warning when the sending domain is not fully authenticated.
    | Delivery is not blocked — a misconfiguration should be visible, not
    | silently drop customer mail.
    |
    */

    'verify_sending_domain' => (bool) env('MAIL_VERIFY_SENDING_DOMAIN', false),

];
