<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature gate labels
    |--------------------------------------------------------------------------
    |
    | Every key here is a gate the application can check through FeatureGate.
    | The stored value lives in plans.feature_flags. Keeping the labels in one
    | place gives middleware, controllers and the frontend the same vocabulary.
    |
    */

    'labels' => [
        'multi_pipeline' => 'Multiple pipelines',
        'tasks' => 'Tasks & reminders',
        'companies' => 'Companies',
        'custom_fields' => 'Custom fields',
        'lead_import' => 'Lead import & export',
        'lead_sources' => 'Lead sources',
        'duplicates' => 'Duplicate detection',
        'forms' => 'Forms',
        'form_submissions' => 'Form submissions',
        'booking' => 'Booking pages',
        'sales_reports' => 'Sales reports',
        'sequences' => 'Email sequences',
        'quotes' => 'Quotes',
        'call_logs' => 'Call log & click-to-call',
        'api_access' => 'API access',
        'white_label' => 'White-label branding',
    ],

];
