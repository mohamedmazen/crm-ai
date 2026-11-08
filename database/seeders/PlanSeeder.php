<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        // Every feature gate resolved to `null` means "enabled, unlimited" (see
        // Plan::hasFeature / Plan::featureLimit), so the trial unlocks 100% of
        // the product without having to keep a second feature list in sync.
        $trialFeatureFlags = array_fill_keys(array_keys(config('features.labels', [])), null);

        // Data limits shared by Business and the free trial. The trial is
        // feature-complete, but its storage/row caps match the largest paid
        // plan so a 60-day signup cannot grow the platform's bill without
        // bound. Keeping one array means the two can never drift apart.
        $businessLimits = [
            'users' => 30,
            'storage' => 256000,
            'max_contacts' => 250000,
            // WhatsApp
            'whatsapp_accounts' => 10,
            'whatsapp_templates' => 200,
            'whatsapp_messages_per_month' => 100000,
            // Broadcasting
            'campaigns_per_month' => 100,
            'sms_per_month' => 50000,
            'emails_per_month' => 100000,
            // Inbox
            'inbox_agents' => 30,
            // AI
            'ai_tokens_per_month' => 10000000,
            'knowledge_bases' => 25,
            'chatbots' => 25,
            // CRM
            'pipelines' => null,
            // Social
            'social_accounts' => 50,
            'social_posts_per_month' => 1000,
            // Leads
            'lead_credits_per_month' => 10000,
            // Automations
            'automations' => null,
        ];

        // Regional prices live in plan_prices (cents). USD stays on the plan row
        // as the base/fallback price, so installs that predate plan_prices keep
        // working. Annual = 10 months, i.e. two months free.
        $regionalPrices = [
            'starter' => [
                'EGP' => [39900, 399000],
                'SAR' => [14900, 149000],
                'AED' => [14900, 149000],
                'USD' => [1900, 19000],
            ],
            'pro' => [
                'EGP' => [129900, 1299000],
                'SAR' => [59900, 599000],
                'AED' => [59900, 599000],
                'USD' => [5900, 59000],
            ],
            'business' => [
                'EGP' => [349900, 3499000],
                'SAR' => [179900, 1799000],
                'AED' => [179900, 1799000],
                'USD' => [17900, 179000],
            ],
        ];

        $plans = [
            // Self-serve 60-day trial. Hidden from the pricing page (enabled=false)
            // because it is not a plan anyone buys — new signups are auto-attached
            // to it by App\Services\Billing\TrialService on registration.
            [
                'name' => 'Free Trial',
                'slug' => 'trial',
                'description' => '60 days of full access — every feature unlocked, with Business-level data limits.',
                'currency_code' => 'USD',
                'price_cents' => 0,
                'interval' => 'month',
                'monthly_price_cents' => 0,
                'yearly_price_cents' => 0,
                'trial_days' => 60,
                'features' => [
                    'Every feature unlocked for 60 days',
                    'Business-level data limits: 30 users · 250,000 contacts · 250 GB',
                    '10 WhatsApp accounts · 100,000 messages/month · 25 chatbots',
                    'No credit card required',
                ],
                // Feature flags stay null (unlimited) while data limits match
                // Business, so the trial feels fully open without letting a
                // single signup grow storage or row counts without bound.
                'limits' => $businessLimits,
                'white_label_enabled' => true,
                'feature_flags' => $trialFeatureFlags,
                'sort_order' => 0,
                'enabled' => false,
                'featured' => false,
                'popular' => false,
            ],
            [
                'name' => 'Free',
                'slug' => 'free',
                'description' => 'The essentials to run a one-person WhatsApp desk.',
                'currency_code' => 'USD',
                'price_cents' => 0,
                'interval' => 'month',
                'monthly_price_cents' => 0,
                'yearly_price_cents' => 0,
                'trial_days' => 0,
                'features' => [
                    '1 user · 1,000 contacts · 1 GB storage',
                    '1 WhatsApp account · 100 messages/month',
                    '1 pipeline · 1 form · 10 social posts/month',
                    '10k AI tokens/month · community support',
                ],
                'limits' => [
                    'users' => 1,
                    'storage' => 1024,
                    'max_contacts' => 1000,
                    'whatsapp_accounts' => 1,
                    'whatsapp_templates' => 3,
                    'whatsapp_messages_per_month' => 100,
                    'campaigns_per_month' => 1,
                    'sms_per_month' => 0,
                    'emails_per_month' => 100,
                    'inbox_agents' => 1,
                    'ai_tokens_per_month' => 10000,
                    'knowledge_bases' => 1,
                    'chatbots' => 0,
                    'pipelines' => 1,
                    'social_accounts' => 1,
                    'social_posts_per_month' => 10,
                    'lead_credits_per_month' => 50,
                    'automations' => 1,
                ],
                'white_label_enabled' => false,
                'feature_flags' => [
                    'multi_pipeline' => false,
                    'tasks' => false,
                    'companies' => false,
                    'custom_fields' => 3,
                    'lead_import' => false,
                    'duplicates' => false,
                    'forms' => 1,
                    'form_submissions' => 100,
                    'lead_sources' => 1,
                    'booking' => false,
                    'sales_reports' => false,
                    'sequences' => 0,
                    'quotes' => false,
                    'call_logs' => false,
                    'api_access' => false,
                    'white_label' => false,
                    'ai_sales' => false,
                    'ai_sdr' => false,
                ],
                'sort_order' => 1,
                'enabled' => true,
                'featured' => false,
                'popular' => false,
            ],
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'For individuals and small teams getting organised on WhatsApp.',
                'currency_code' => 'USD',
                'price_cents' => 1900,
                'interval' => 'month',
                'monthly_price_cents' => 1900,
                'yearly_price_cents' => 19000,
                'trial_days' => 0,
                'features' => [
                    '3 users · 5,000 contacts · 5 GB storage',
                    '1 WhatsApp account · 2,000 messages/month',
                    '2 pipelines · 10 campaigns/month · tasks & companies',
                    '2 chatbots · 2 knowledge bases · 250k AI tokens',
                    'Quotes · lead import · basic sales reports',
                    'Email support',
                ],
                'limits' => [
                    'users' => 3,
                    'storage' => 5120,
                    'max_contacts' => 5000,
                    // WhatsApp
                    'whatsapp_accounts' => 1,
                    'whatsapp_templates' => 20,
                    'whatsapp_messages_per_month' => 2000,
                    // Broadcasting
                    'campaigns_per_month' => 10,
                    'sms_per_month' => 500,
                    'emails_per_month' => 2000,
                    // Inbox
                    'inbox_agents' => 3,
                    // AI
                    'ai_tokens_per_month' => 250000,
                    'knowledge_bases' => 2,
                    'chatbots' => 2,
                    // CRM
                    'pipelines' => 2,
                    // Social
                    'social_accounts' => 2,
                    'social_posts_per_month' => 30,
                    // Leads
                    'lead_credits_per_month' => 500,
                    // Automations
                    'automations' => 5,
                ],
                'white_label_enabled' => false,
                'feature_flags' => [
                    'multi_pipeline' => 2,
                    'tasks' => true,
                    'companies' => true,
                    'custom_fields' => 10,
                    'lead_import' => true,
                    'duplicates' => true,
                    'forms' => 3,
                    'form_submissions' => 300,
                    'lead_sources' => 5,
                    'booking' => false,
                    'sales_reports' => true,
                    'sequences' => 0,
                    'quotes' => true,
                    'call_logs' => true,
                    'api_access' => false,
                    'white_label' => false,
                    'ai_sales' => false,
                    'ai_sdr' => false,
                ],
                'sort_order' => 2,
                'enabled' => true,
                'featured' => false,
                'popular' => false,
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'description' => 'For growing teams that need scale, automation and AI.',
                'currency_code' => 'USD',
                'price_cents' => 5900,
                'interval' => 'month',
                'monthly_price_cents' => 5900,
                'yearly_price_cents' => 59000,
                'trial_days' => 0,
                'features' => [
                    '10 users · 50,000 contacts · 50 GB storage',
                    '3 WhatsApp accounts · 20,000 messages/month',
                    'Unlimited pipelines · 5 chatbots · 2M AI tokens',
                    'API access · email sequences · booking pages',
                    'AI sales assistant · advanced reports',
                    'Priority support',
                ],
                'limits' => [
                    'users' => 10,
                    'storage' => 51200,
                    'max_contacts' => 50000,
                    'whatsapp_accounts' => 3,
                    'whatsapp_templates' => 50,
                    'whatsapp_messages_per_month' => 20000,
                    'campaigns_per_month' => 30,
                    'sms_per_month' => 10000,
                    'emails_per_month' => 25000,
                    'inbox_agents' => 10,
                    'ai_tokens_per_month' => 2000000,
                    'knowledge_bases' => 5,
                    'chatbots' => 5,
                    'pipelines' => null,
                    'social_accounts' => 10,
                    'social_posts_per_month' => 200,
                    'lead_credits_per_month' => 2000,
                    'automations' => 20,
                ],
                'white_label_enabled' => false,
                'feature_flags' => [
                    'multi_pipeline' => true,
                    'tasks' => true,
                    'companies' => true,
                    'custom_fields' => 20,
                    'lead_import' => true,
                    'duplicates' => true,
                    'forms' => 10,
                    'form_submissions' => 5000,
                    'lead_sources' => 15,
                    'booking' => true,
                    'sales_reports' => true,
                    'sequences' => 5,
                    'quotes' => true,
                    'call_logs' => true,
                    'api_access' => true,
                    'white_label' => false,
                    'ai_sales' => true,
                    'ai_sdr' => false,
                ],
                'sort_order' => 3,
                'enabled' => true,
                'featured' => false,
                'popular' => true,
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'description' => 'Enterprise-grade features for large organisations.',
                'currency_code' => 'USD',
                'price_cents' => 17900,
                'interval' => 'month',
                'monthly_price_cents' => 17900,
                'yearly_price_cents' => 179000,
                'trial_days' => 0,
                'features' => [
                    '30 users · 250,000 contacts · 250 GB storage',
                    '10 WhatsApp accounts · 100,000 messages/month',
                    'White-label branding + custom domain',
                    'AI SDR · unlimited automations · 25 chatbots',
                    'Dedicated support + SLA + onboarding',
                ],
                'limits' => $businessLimits,
                'white_label_enabled' => true,
                'feature_flags' => [
                    'multi_pipeline' => true,
                    'tasks' => true,
                    'companies' => true,
                    'custom_fields' => null,
                    'lead_import' => true,
                    'duplicates' => true,
                    'forms' => null,
                    'form_submissions' => null,
                    'lead_sources' => null,
                    'booking' => true,
                    'sales_reports' => true,
                    'sequences' => null,
                    'quotes' => true,
                    'call_logs' => true,
                    'api_access' => true,
                    'white_label' => true,
                    'ai_sales' => true,
                    'ai_sdr' => true,
                ],
                'sort_order' => 4,
                'enabled' => true,
                'featured' => true,
                'popular' => false,
            ],
        ];

        foreach ($plans as $row) {
            $prices = $regionalPrices[$row['slug']] ?? [];
            unset($row['prices']);

            $plan = Plan::updateOrCreate(
                ['slug' => $row['slug']],
                $row
            );

            foreach ($prices as $currencyCode => [$monthly, $yearly]) {
                $plan->prices()->updateOrCreate(
                    ['currency_code' => $currencyCode],
                    [
                        'monthly_price_cents' => $monthly,
                        'yearly_price_cents' => $yearly,
                    ]
                );
            }
        }
    }
}
