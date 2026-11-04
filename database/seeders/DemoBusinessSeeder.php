<?php

namespace Database\Seeders;

/**
 * Lightweight demo tenant for the Business tier.
 *
 * The broadest dataset: three pipelines, every channel and a full team, so the
 * sales team can demo unlimited seats, white-label, API access and the AI SDR
 * without a large database.
 */
class DemoBusinessSeeder extends DemoTenantSeeder
{
    protected function config(): array
    {
        return [
            'key' => 'business',
            'tier' => 'Business',
            'plan' => 'business',
            'company' => '360 Business',
            'email' => 'business@aydra.demo',
            'password' => 'business@aydra.demo',
            'admin_name' => 'Mazen Business',
            'display_phone' => '+1 555 0199',
            'color' => '#7C3AED',
            'timezone' => 'Africa/Cairo',
            'tagline' => 'Stronger teams, stronger business.',
            'contacts' => 30,
            'leads' => 120,
            'conversations' => 12,
            'channels' => ['whatsapp', 'instagram', 'messenger', 'sms', 'email'],
            'pipelines' => [
                ['name' => 'Sales Pipeline', 'default' => true],
                ['name' => 'Enterprise Sales', 'default' => false],
                ['name' => 'Partnerships', 'default' => false],
            ],
            'team' => [
                ['name' => 'Sofia Ricci', 'email' => 'sofia@helios.demo'],
                ['name' => 'Tariq Haddad', 'email' => 'tariq@helios.demo'],
                ['name' => 'Nina Kowalski', 'email' => 'nina@helios.demo'],
                ['name' => 'Ben Okafor', 'email' => 'ben@helios.demo'],
            ],
            'lead_category' => 'Fitness Studio',
            'lead_cities' => ['Chicago', 'Nashville', 'Miami', 'Columbus'],
            'topics' => [
                [
                    'in' => 'Can you quote corporate memberships for 50 staff?',
                    'out' => 'Hi {{name}}! Absolutely — our corporate plan is $29 per employee per month with a dedicated account manager. Would you like a formal quote?',
                ],
                [
                    'in' => 'Do you offer on-site classes for companies?',
                    'out' => 'We do — on-site HIIT and yoga sessions start at $220 per class. Shall I send over our availability?',
                ],
                [
                    'in' => 'What is included in the annual plan?',
                    'out' => 'The annual plan includes every location, 24/7 access and two personal training sessions a month. I will email the full details, {{name}}.',
                ],
                [
                    'in' => 'We need to onboard 120 employees next quarter.',
                    'out' => 'Perfect — we will assign a rollout manager and stagger onboarding so nobody misses a beat. Can we book a 20-minute call?',
                ],
                [
                    'in' => 'Can we trial the multi-site package first?',
                    'out' => 'Of course — I will set up a 30-day pilot across two locations with no commitment. Which sites would you like to start with?',
                ],
            ],
        ];
    }
}
