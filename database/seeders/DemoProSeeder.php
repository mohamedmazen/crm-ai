<?php

namespace Database\Seeders;

/**
 * Lightweight demo tenant for the Pro tier.
 *
 * Two pipelines, three channels and a small team, so the sales team can demo
 * automation, assignment and multi-pipeline workflows without the full
 * Business dataset.
 */
class DemoProSeeder extends DemoTenantSeeder
{
    protected function config(): array
    {
        return [
            'key' => 'pro',
            'tier' => 'Pro',
            'plan' => 'pro',
            'company' => 'Northwind Realty',
            'email' => 'pro@aydra.demo',
            'password' => '12345678',
            'admin_name' => 'Daniel Reeves',
            'display_phone' => '+1 555 0177',
            'color' => '#2563EB',
            'timezone' => 'America/New_York',
            'tagline' => 'Find the address you will call home.',
            'contacts' => 20,
            'leads' => 60,
            'conversations' => 8,
            'channels' => ['whatsapp', 'instagram', 'messenger'],
            'pipelines' => [
                ['name' => 'Sales Pipeline', 'default' => true],
                ['name' => 'Enterprise Sales', 'default' => false],
            ],
            'team' => [
                ['name' => 'Priya Natarajan', 'email' => 'priya@northwind.demo'],
                ['name' => 'Marcus Bell', 'email' => 'marcus@northwind.demo'],
            ],
            'lead_category' => 'Real Estate Agency',
            'lead_cities' => ['Charlotte', 'Raleigh', 'Atlanta', 'Nashville'],
            'topics' => [
                [
                    'in' => 'Is the 3-bed on Maple Street still available?',
                    'out' => 'Hi {{name}}! Yes, it is still on the market. Would you like to book a viewing this week?',
                ],
                [
                    'in' => 'What are your commission rates?',
                    'out' => 'Our standard listing fee is 2.5%. I can email the full breakdown — what is the property address, {{name}}?',
                ],
                [
                    'in' => 'Can we schedule a viewing for Saturday?',
                    'out' => 'Saturday works well. I will hold 11am for you — does that suit?',
                ],
                [
                    'in' => 'We are also looking at a commercial unit.',
                    'out' => 'Great — our commercial team specialises in exactly that. I will connect you with a specialist today.',
                ],
                [
                    'in' => 'We would like to make an offer.',
                    'out' => 'Exciting news {{name}}! I will prepare the paperwork and send it over within the hour.',
                ],
            ],
        ];
    }
}
