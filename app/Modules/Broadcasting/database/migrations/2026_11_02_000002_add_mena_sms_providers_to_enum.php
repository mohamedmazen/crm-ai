<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL: extend the ENUM with the MENA-focused providers
        // (SMS Misr, CEQUENS, Unifonic, Taqnyat, Msegat) plus Infobip.
        DB::statement("
            ALTER TABLE sms_provider_configs
            MODIFY COLUMN provider ENUM(
                'twilio','nexmo','telnyx','clicksend','amazon_sns',
                'sms_misr','cequens','unifonic','taqnyat','msegat','infobip'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        // Remove any stored configs for the providers being pruned before
        // tightening the ENUM, otherwise MySQL rejects the ALTER.
        DB::table('sms_provider_configs')
            ->whereIn('provider', ['sms_misr', 'cequens', 'unifonic', 'taqnyat', 'msegat', 'infobip'])
            ->delete();

        DB::statement("
            ALTER TABLE sms_provider_configs
            MODIFY COLUMN provider ENUM(
                'twilio','nexmo','telnyx','clicksend','amazon_sns'
            ) NOT NULL
        ");
    }
};
