<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Replace the placeholder currency set (EUR, GBP, BDT) with the currencies
     * offered in the MENA market: USD plus the Egyptian pound and the GCC
     * currencies. Removed currencies are disabled rather than deleted so any
     * historical rows that still reference their code keep resolving.
     *
     * exchange_rate follows CurrencyService::convert() — the value is "units of
     * the reference currency (USD, rate 1) per 1 unit of this currency". Rates
     * are indicative and editable from Admin → Currencies.
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

    /** Currencies this migration retires from the selectable list. */
    private const RETIRED = ['EUR', 'GBP', 'BDT'];

    public function up(): void
    {
        $now = now();

        foreach (self::CURRENCIES as $row) {
            if (DB::table('currencies')->where('code', $row['code'])->exists()) {
                DB::table('currencies')->where('code', $row['code'])->update($row + ['updated_at' => $now]);
            } else {
                DB::table('currencies')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
            }
        }

        // USD is the single platform default.
        DB::table('currencies')->where('code', '!=', 'USD')->update(['is_default' => false]);
        DB::table('currencies')->where('code', 'USD')->update(['is_default' => true, 'enabled' => true]);

        // Retire the currencies we no longer offer.
        DB::table('currencies')
            ->whereIn('code', self::RETIRED)
            ->update(['enabled' => false, 'is_default' => false]);

        // Move any user who had picked a retired currency back to the default,
        // otherwise their select value no longer matches the offered options.
        DB::table('users')
            ->whereIn('display_currency', self::RETIRED)
            ->update(['display_currency' => 'USD']);
    }

    public function down(): void
    {
        $now = now();

        $legacy = [
            ['code' => 'EUR', 'symbol' => '€', 'decimals' => 2, 'exchange_rate' => 1.087, 'is_default' => false, 'enabled' => true],
            ['code' => 'GBP', 'symbol' => '£', 'decimals' => 2, 'exchange_rate' => 1.266, 'is_default' => false, 'enabled' => true],
            ['code' => 'BDT', 'symbol' => '৳', 'decimals' => 2, 'exchange_rate' => 0.0091, 'is_default' => false, 'enabled' => true],
        ];

        foreach ($legacy as $row) {
            if (DB::table('currencies')->where('code', $row['code'])->exists()) {
                DB::table('currencies')->where('code', $row['code'])->update($row + ['updated_at' => $now]);
            } else {
                DB::table('currencies')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
            }
        }

        // Remove the currencies introduced by this migration (except USD).
        DB::table('currencies')
            ->whereIn('code', ['EGP', 'SAR', 'AED', 'KWD', 'QAR', 'BHD', 'OMR'])
            ->delete();
    }
};
