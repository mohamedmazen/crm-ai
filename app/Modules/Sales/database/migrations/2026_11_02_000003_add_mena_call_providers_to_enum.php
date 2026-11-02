<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL: extend the ENUM with the MENA-focused call gateways
        // (CEQUENS for Egypt/Gulf, Unifonic for KSA/UAE).
        DB::statement("
            ALTER TABLE call_provider_configs
            MODIFY COLUMN provider ENUM('twilio','telnyx','cequens','unifonic') NOT NULL
        ");
    }

    public function down(): void
    {
        // Remove stored configs for the providers being pruned before tightening
        // the ENUM, otherwise MySQL rejects the ALTER.
        DB::table('call_provider_configs')
            ->whereIn('provider', ['cequens', 'unifonic'])
            ->delete();

        DB::statement("
            ALTER TABLE call_provider_configs
            MODIFY COLUMN provider ENUM('twilio','telnyx') NOT NULL
        ");
    }
};
