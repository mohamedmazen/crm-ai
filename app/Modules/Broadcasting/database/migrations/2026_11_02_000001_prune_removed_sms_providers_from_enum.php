<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Providers removed from the application. */
    private const REMOVED_PROVIDERS = [
        'messagebird',
        'plivo',
        'infobip',
        'smsbd',
        'reve',
        'bulksmsbd',
        'sms_dot_bd',
        'mimsms',
        'fast2sms',
        'msg91',
    ];

    public function up(): void
    {
        // Drop any stored configs for providers that no longer exist before
        // tightening the ENUM, otherwise MySQL rejects the ALTER.
        DB::table('sms_provider_configs')
            ->whereIn('provider', self::REMOVED_PROVIDERS)
            ->delete();

        DB::statement("
            ALTER TABLE sms_provider_configs
            MODIFY COLUMN provider ENUM(
                'twilio','nexmo','telnyx','clicksend','amazon_sns'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE sms_provider_configs
            MODIFY COLUMN provider ENUM(
                'twilio','nexmo','messagebird','plivo','telnyx','infobip','clicksend',
                'smsbd','reve','bulksmsbd','sms_dot_bd','mimsms','fast2sms','msg91','amazon_sns'
            ) NOT NULL
        ");
    }
};
