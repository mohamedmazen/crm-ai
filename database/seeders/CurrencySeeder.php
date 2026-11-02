<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    /**
     * exchange_rate = reference units (USD, rate 1) per 1 unit of this currency.
     * See CurrencyService::convert(). Rates are indicative and editable from
     * Admin → Currencies.
     */
    private const CURRENCIES = [
        ['code' => 'USD', 'symbol' => '$', 'decimals' => 2, 'exchange_rate' => 1.0, 'is_default' => true, 'enabled' => true],
        ['code' => 'EGP', 'symbol' => 'ج.م', 'decimals' => 2, 'exchange_rate' => 0.02, 'is_default' => false, 'enabled' => true],
        ['code' => 'SAR', 'symbol' => 'ر.س', 'decimals' => 2, 'exchange_rate' => 0.26666667, 'is_default' => false, 'enabled' => true],
        ['code' => 'AED', 'symbol' => 'د.إ', 'decimals' => 2, 'exchange_rate' => 0.27229408, 'is_default' => false, 'enabled' => true],
        ['code' => 'KWD', 'symbol' => 'د.ك', 'decimals' => 3, 'exchange_rate' => 3.2552, 'is_default' => false, 'enabled' => true],
        ['code' => 'QAR', 'symbol' => 'ر.ق', 'decimals' => 2, 'exchange_rate' => 0.27472527, 'is_default' => false, 'enabled' => true],
        ['code' => 'BHD', 'symbol' => 'د.ب', 'decimals' => 3, 'exchange_rate' => 2.65957447, 'is_default' => false, 'enabled' => true],
        ['code' => 'OMR', 'symbol' => 'ر.ع', 'decimals' => 3, 'exchange_rate' => 2.60078023, 'is_default' => false, 'enabled' => true],
    ];

    /** Currencies removed from the selectable list (kept, disabled). */
    private const RETIRED = ['EUR', 'GBP', 'BDT'];

    public function run(): void
    {
        foreach (self::CURRENCIES as $row) {
            Currency::updateOrCreate(
                ['code' => $row['code']],
                $row
            );
        }

        // USD is the single platform default.
        Currency::query()->where('code', '!=', 'USD')->update(['is_default' => false]);
        Currency::query()->where('code', 'USD')->update(['is_default' => true, 'enabled' => true]);

        // Retire the currencies we no longer offer.
        Currency::query()
            ->whereIn('code', self::RETIRED)
            ->update(['enabled' => false, 'is_default' => false]);
    }
}
