<?php

namespace Database\Seeders;

use App\Modules\Integrations\Database\Seeders\IntegrationConfigSeeder;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            PermissionSeeder::class,
            RoleSeeder::class,
            AdminUserSeeder::class,
            CurrencySeeder::class,
            LocaleSeeder::class,
            TranslationSeeder::class,
            PlanSeeder::class,
            PaymentGatewayConfigSeeder::class,
            EmailTemplateSeeder::class,
            IntegrationConfigSeeder::class,
            SmtpConfigurationSeeder::class,
            LandingPageSeeder::class,
            CmsPageSeeder::class,

            // Lightweight, tier-specific demo tenants so sales can run live
            // proposals: Starter (1 pipeline, WhatsApp), Pro (+IG/Messenger,
            // 2 pipelines, small team) and Business (full channels + team).
            // Each seeder prints its login on completion.
            DemoStarterSeeder::class,
            DemoProSeeder::class,
            DemoBusinessSeeder::class,
        ]);

        // User::factory(10)->create();
        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);
    }
}
