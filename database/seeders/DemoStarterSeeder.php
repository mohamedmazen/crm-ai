<?php

namespace Database\Seeders;

/**
 * Lightweight demo tenant for the Starter tier.
 *
 * A single-pipeline, low-volume account so the sales team can show what a
 * customer sees at the entry price point — including the features Starter
 * locks (companies, tasks, multiple pipelines, AI SDR, API access, ...).
 */
class DemoStarterSeeder extends DemoTenantSeeder
{
    protected function config(): array
    {
        return [
            'key' => 'starter',
            'tier' => 'Starter',
            'plan' => 'starter',
            'company' => '360 Starter',
            'email' => 'starter@aydra.demo',
            'password' => 'starter@aydra.demo',
            'admin_name' => 'Mazen Starter',
            'display_phone' => '+1 555 0142',
            'color' => '#0E7C5A',
            'timezone' => 'Africa/Cairo',
            'tagline' => 'Breathe. Stretch. Belong.',
            'contacts' => 10,
            'leads' => 24,
            'conversations' => 4,
            'channels' => ['whatsapp'],
            'pipelines' => [
                ['name' => 'Sales Pipeline', 'default' => true],
            ],
            'team' => [],
            'lead_category' => 'Yoga Studio',
            'lead_cities' => ['Austin', 'Dallas', 'Denver', 'Phoenix'],
            'topics' => [
                [
                    'in' => "Hi! I'd love to try a beginner class this weekend — do you have space?",
                    'out' => 'Hi {{name}} 🌿 Yes! Saturday 10am and Sunday 9am both have spots. Want me to reserve one for you?',
                ],
                [
                    'in' => 'How much is a monthly membership?',
                    'out' => 'Our unlimited membership is $89/month and your first class is free. Shall I set up a guest pass, {{name}}?',
                ],
                [
                    'in' => 'Can I book a private session?',
                    'out' => 'Absolutely — private sessions are $70/hr with any of our instructors. Which day suits you best?',
                ],
                [
                    'in' => 'Do you offer a free trial?',
                    'out' => 'Yes! Your first class is on us 💚 Let me know a day and time and I will book you in, {{name}}.',
                ],
                [
                    'in' => "I'd like to reschedule tomorrow's class please.",
                    'out' => 'No problem {{name}} — I have moved you to the same time next week. See you on the mat! 🧘',
                ],
            ],
        ];
    }
}
