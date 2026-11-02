<?php

namespace Tests\Unit;

use App\Support\Brand;
use Tests\TestCase;

class BrandTest extends TestCase
{
    public function test_name_uses_the_base_brand_for_non_arabic_locales(): void
    {
        config(['app.name' => 'AYDRA', 'saas.app_name_ar' => 'أيدرا']);

        $this->assertSame('AYDRA', Brand::localizedName('en'));
        $this->assertSame('AYDRA', Brand::name('en'));
    }

    public function test_name_uses_the_arabic_brand_for_the_arabic_locale(): void
    {
        config(['app.name' => 'AYDRA', 'saas.app_name_ar' => 'أيدرا']);

        $this->assertSame('أيدرا', Brand::localizedName('ar'));
        $this->assertSame('أيدرا', Brand::name('ar'));
    }

    public function test_name_falls_back_to_the_base_brand_when_arabic_is_unset(): void
    {
        config(['app.name' => 'AYDRA', 'saas.app_name_ar' => null]);

        $this->assertSame('AYDRA', Brand::name('ar'));
    }

    public function test_tagline_is_locale_aware(): void
    {
        config([
            'saas.tagline' => 'Your Business. Smarter.',
            'saas.tagline_ar' => 'أعمالك، بذكاء أكبر.',
        ]);

        $this->assertSame('Your Business. Smarter.', Brand::tagline('en'));
        $this->assertSame('أعمالك، بذكاء أكبر.', Brand::tagline('ar'));
    }

    public function test_brand_token_resolves_to_the_localized_name(): void
    {
        config(['app.name' => 'AYDRA', 'saas.app_name_ar' => 'أيدرا']);

        app()->setLocale('en');
        $this->assertSame('AYDRA', Brand::apply(':brand'));

        app()->setLocale('ar');
        $this->assertSame('أيدرا', Brand::apply(':brand'));
    }

    public function test_name_uses_the_active_locale_when_none_is_passed(): void
    {
        config(['app.name' => 'AYDRA', 'saas.app_name_ar' => 'أيدرا']);

        app()->setLocale('ar');
        $this->assertSame('أيدرا', Brand::name());

        app()->setLocale('en');
        $this->assertSame('AYDRA', Brand::name());
    }
}
