<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Services\Billing\BillingGatewayRegistry;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegionalPlanPricingTest extends TestCase
{
    use RefreshDatabase;

    private function seedPlans(): void
    {
        $this->seed([CurrencySeeder::class, PlanSeeder::class]);
    }

    public function test_plan_resolves_regional_prices(): void
    {
        $this->seedPlans();

        $starter = Plan::where('slug', 'starter')->firstOrFail();

        $this->assertSame(['cents' => 39900, 'currency' => 'EGP'], $starter->priceQuote('month', 'EGP'));
        $this->assertSame(['cents' => 399000, 'currency' => 'EGP'], $starter->priceQuote('year', 'EGP'));
        $this->assertSame(['cents' => 14900, 'currency' => 'SAR'], $starter->priceQuote('month', 'SAR'));
        $this->assertSame(['cents' => 14900, 'currency' => 'AED'], $starter->priceQuote('month', 'AED'));
        $this->assertSame(['cents' => 1900, 'currency' => 'USD'], $starter->priceQuote('month', 'USD'));
        $this->assertNull($starter->priceQuote('month', 'KWD'));
    }

    public function test_business_regional_prices_and_annual_discount(): void
    {
        $this->seedPlans();

        $business = Plan::where('slug', 'business')->firstOrFail();

        $this->assertSame(349900, $business->priceQuote('month', 'EGP')['cents']);
        $this->assertSame(3499000, $business->priceQuote('year', 'EGP')['cents']);
        $this->assertSame(179900, $business->priceQuote('month', 'SAR')['cents']);
        $this->assertSame(17900, $business->priceQuote('month', 'USD')['cents']);

        // Annual billing is ten monthly payments (two months free).
        $this->assertSame(
            $business->priceQuote('month', 'EGP')['cents'] * 10,
            $business->priceQuote('year', 'EGP')['cents']
        );
    }

    public function test_starter_plan_includes_crm_essentials(): void
    {
        $this->seedPlans();

        $starter = Plan::where('slug', 'starter')->firstOrFail();

        $this->assertSame(3, $starter->limitValue('users'));
        $this->assertSame(5000, $starter->limitValue('max_contacts'));
        $this->assertSame(2000, $starter->limitValue('whatsapp_messages_per_month'));
        $this->assertSame(250000, $starter->limitValue('ai_tokens_per_month'));
        $this->assertSame(2, $starter->limitValue('pipelines'));

        foreach (['tasks', 'companies', 'lead_import', 'duplicates', 'sales_reports', 'quotes', 'call_logs'] as $feature) {
            $this->assertTrue($starter->hasFeature($feature), "Starter should include {$feature}.");
        }

        $this->assertFalse($starter->hasFeature('api_access'));
        $this->assertFalse($starter->hasFeature('booking'));
        $this->assertFalse($starter->hasFeature('ai_sales'));
        $this->assertFalse($starter->hasFeature('ai_sdr'));
    }

    public function test_free_plan_is_free_and_starter_is_paid(): void
    {
        $this->seedPlans();

        $this->assertTrue(Plan::where('slug', 'free')->firstOrFail()->isFree());
        $this->assertFalse(Plan::where('slug', 'starter')->firstOrFail()->isFree());
        $this->assertFalse(Plan::where('slug', 'trial')->firstOrFail()->enabled);
    }

    public function test_pricing_page_shows_the_selected_regional_price(): void
    {
        $this->seedPlans();

        $response = $this->get(route('pricing', ['currency' => 'EGP']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('currency', 'EGP')
            ->has('plans', 4)
            ->where('plans', function ($plans) {
                $plans = collect($plans);
                $starter = $plans->firstWhere('slug', 'starter');
                $pro = $plans->firstWhere('slug', 'pro');

                return $starter['price_monthly_display'] === 'ج.م399.00'
                    && $starter['price_yearly_display'] === 'ج.م3,990.00'
                    && $pro['price_monthly_display'] === 'ج.م1,299.00';
            }));
    }

    public function test_gateways_are_filtered_by_supported_currency(): void
    {
        $this->assertTrue(BillingGatewayRegistry::supportsCurrency('fawry', 'EGP'));
        $this->assertFalse(BillingGatewayRegistry::supportsCurrency('fawry', 'SAR'));
        $this->assertTrue(BillingGatewayRegistry::supportsCurrency('myfatoorah', 'SAR'));
        $this->assertFalse(BillingGatewayRegistry::supportsCurrency('paypal', 'EGP'));
        $this->assertTrue(BillingGatewayRegistry::supportsCurrency('tap', 'AED'));
    }
}
