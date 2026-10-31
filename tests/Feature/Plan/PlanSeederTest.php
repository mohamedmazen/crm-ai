<?php

namespace Tests\Feature\Plan;

use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_starter_only_includes_the_entry_level_features(): void
    {
        $starter = Plan::where('slug', 'starter')->firstOrFail();

        $this->assertFalse($starter->hasFeature('multi_pipeline'));
        $this->assertFalse($starter->hasFeature('tasks'));
        $this->assertFalse($starter->hasFeature('companies'));
        $this->assertFalse($starter->hasFeature('lead_import'));
        $this->assertFalse($starter->hasFeature('duplicates'));
        $this->assertFalse($starter->hasFeature('booking'));
        $this->assertFalse($starter->hasFeature('sales_reports'));
        $this->assertFalse($starter->hasFeature('quotes'));
        $this->assertFalse($starter->hasFeature('api_access'));
        $this->assertFalse($starter->hasFeature('white_label'));

        $this->assertTrue($starter->hasFeature('custom_fields'));
        $this->assertSame(5, $starter->featureLimit('custom_fields'));

        $this->assertTrue($starter->hasFeature('forms'));
        $this->assertSame(1, $starter->featureLimit('forms'));

        $this->assertSame(100, $starter->featureLimit('form_submissions'));
        $this->assertSame(0, $starter->featureLimit('sequences'));
        $this->assertSame(0, $starter->featureLimit('landing_pages'));
    }

    public function test_pro_unlocks_the_core_crm_and_sales_modules(): void
    {
        $pro = Plan::where('slug', 'pro')->firstOrFail();

        foreach (['multi_pipeline', 'tasks', 'companies', 'lead_import', 'duplicates', 'booking', 'sales_reports', 'quotes', 'api_access'] as $feature) {
            $this->assertTrue($pro->hasFeature($feature), "Pro should include {$feature}.");
        }

        $this->assertFalse($pro->hasFeature('white_label'));

        $this->assertSame(20, $pro->featureLimit('custom_fields'));
        $this->assertSame(10, $pro->featureLimit('forms'));
        $this->assertSame(5000, $pro->featureLimit('form_submissions'));
        $this->assertSame(5, $pro->featureLimit('sequences'));
        $this->assertSame(5, $pro->featureLimit('landing_pages'));
    }

    public function test_business_unlocks_everything_without_caps_where_advertised(): void
    {
        $business = Plan::where('slug', 'business')->firstOrFail();

        foreach (array_keys(config('features.labels')) as $feature) {
            $this->assertTrue($business->hasFeature($feature), "Business should include {$feature}.");
        }

        foreach (['custom_fields', 'forms', 'form_submissions', 'sequences', 'landing_pages'] as $feature) {
            $this->assertNull($business->featureLimit($feature));
        }
    }
}
