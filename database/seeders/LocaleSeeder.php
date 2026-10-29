<?php

namespace Database\Seeders;

use App\Models\Locale;
use Illuminate\Database\Seeder;

class LocaleSeeder extends Seeder
{
    /**
     * Seed default languages: English (default) and Arabic (RTL).
     * Idempotent: updateOrCreate by code and removes non-supported locales.
     */
    public function run(): void
    {
        $defaultCode = 'en';

        $locales = [
            [
                'code' => 'en',
                'name' => 'English',
                'native_name' => 'English',
                'flag' => null,
                'enabled' => true,
                'is_default' => true,
                'is_rtl' => false,
                'sort_order' => 1,
            ],
            [
                'code' => 'ar',
                'name' => 'Arabic',
                'native_name' => 'العربية',
                'flag' => null,
                'enabled' => true,
                'is_default' => false,
                'is_rtl' => true,
                'sort_order' => 2,
            ],
        ];

        // Remove any locales outside supported list
        Locale::whereNotIn('code', ['en', 'ar'])->delete();

        // Ensure only one default
        Locale::where('is_default', true)->update(['is_default' => false]);

        foreach ($locales as $row) {
            Locale::updateOrCreate(
                ['code' => $row['code']],
                $row
            );
        }
    }
}
