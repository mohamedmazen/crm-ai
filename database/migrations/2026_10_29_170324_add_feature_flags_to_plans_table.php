<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Default gates for the plans that ship with the application. Kept here as
     * well as in PlanSeeder so existing installs receive the flags without
     * having to re-run seeders. A null value means "enabled, unlimited".
     *
     * @var array<string, array<string, bool|int|null>>
     */
    private const DEFAULTS = [
        'starter' => [
            'multi_pipeline' => false,
            'tasks' => false,
            'companies' => false,
            'custom_fields' => 5,
            'lead_import' => false,
            'forms' => 1,
            'form_submissions' => 100,
            'booking' => false,
            'sales_reports' => false,
            'sequences' => 0,
            'quotes' => false,
            'landing_pages' => 0,
            'api_access' => false,
            'white_label' => false,
        ],
        'pro' => [
            'multi_pipeline' => true,
            'tasks' => true,
            'companies' => true,
            'custom_fields' => 20,
            'lead_import' => true,
            'forms' => 10,
            'form_submissions' => 5000,
            'booking' => true,
            'sales_reports' => true,
            'sequences' => 5,
            'quotes' => true,
            'landing_pages' => 5,
            'api_access' => true,
            'white_label' => false,
        ],
        'business' => [
            'multi_pipeline' => true,
            'tasks' => true,
            'companies' => true,
            'custom_fields' => null,
            'lead_import' => true,
            'forms' => null,
            'form_submissions' => null,
            'booking' => true,
            'sales_reports' => true,
            'sequences' => null,
            'quotes' => true,
            'landing_pages' => null,
            'api_access' => true,
            'white_label' => true,
        ],
    ];

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->json('feature_flags')->nullable()->after('limits');
        });

        foreach (self::DEFAULTS as $slug => $flags) {
            DB::table('plans')
                ->where('slug', $slug)
                ->whereNull('feature_flags')
                ->update(['feature_flags' => json_encode($flags)]);
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('feature_flags');
        });
    }
};
