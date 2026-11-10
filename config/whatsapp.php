<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Meta Webhook Fields
    |--------------------------------------------------------------------------
    |
    | The subscription fields for the app-global WhatsApp callback URL. This is
    | the union of the standard Cloud API fields and the coexistence-only fields
    | (`history`, `smb_app_state_sync`, `smb_message_echoes`), so registering the
    | callback never drops coexistence events for already-connected numbers.
    |
    */

    'webhook_fields' => 'messages,message_template_status_update,phone_number_name_update,phone_number_quality_update,account_update,history,smb_app_state_sync,smb_message_echoes',

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Coexistence
    |--------------------------------------------------------------------------
    |
    | A number can stay in the WhatsApp Business app while also being served by
    | Cloud API. Only Pro and Business plans unlock the onboarding flow (the
    | `whatsapp_coexistence` feature gate); `enabled` is a platform-wide kill
    | switch for incidents or while Meta approval is pending.
    |
    */

    'coexistence' => [
        'enabled' => env('WHATSAPP_COEXISTENCE_ENABLED', true),

        // Meta only accepts the history/contacts sync request once, within 24
        // hours of onboarding. Missing the window requires offboarding first.
        'history_sync_window_hours' => (int) env('WHATSAPP_COEXISTENCE_HISTORY_WINDOW_HOURS', 24),

        // Coexistence numbers are fixed at 20 messages/second by Meta. Keep a
        // safety margin so retries and other calls do not trip the limit.
        'max_messages_per_second' => (int) env('WHATSAPP_COEXISTENCE_MPS', 18),
    ],

];
