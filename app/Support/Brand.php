<?php

namespace App\Support;

/**
 * Brand-name token used inside stored, admin-editable content.
 *
 * Marketing copy and CMS pages are seeded into the database, so interpolating the
 * brand name at seed time would freeze whatever it was at install and leave the
 * content stale after a rename. Storing `:brand` and resolving it on read keeps
 * every white-label install correct, however often the brand changes.
 */
class Brand
{
    public const TOKEN = ':brand';

    /**
     * The brand name for the active locale. The Arabic interface shows the
     * Arabic name (saas.app_name_ar); every other locale shows the
     * admin-configured / .env name.
     */
    public static function name(?string $locale = null): string
    {
        return self::localizedName($locale);
    }

    /** The brand name for a locale, falling back to the base name when unset. */
    public static function localizedName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        if (self::isArabic($locale)) {
            return (string) (config('saas.app_name_ar') ?: config('app.name'));
        }

        return (string) config('app.name');
    }

    /** The slogan for the active locale (saas.tagline / saas.tagline_ar). */
    public static function tagline(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        if (self::isArabic($locale)) {
            return (string) (config('saas.tagline_ar') ?: config('saas.tagline'));
        }

        return (string) config('saas.tagline');
    }

    private static function isArabic(string $locale): bool
    {
        return str_starts_with(strtolower($locale), 'ar');
    }

    /** Resolve the token in a single value. Safe to call on null. */
    public static function apply(?string $value): string
    {
        return str_replace(self::TOKEN, self::name(), (string) $value);
    }

    /**
     * Resolve the token across an array of values, leaving non-strings untouched.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function applyMany(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = self::apply($value);
            }
        }

        return $values;
    }
}
