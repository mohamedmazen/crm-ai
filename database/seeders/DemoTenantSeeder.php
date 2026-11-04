<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inbox\Models\CannedReply;
use App\Modules\Inbox\Models\InboxLabel;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadPipeline;
use App\Modules\Leads\Models\LeadStageHistory;
use App\Modules\Leads\Models\LeadTag;
use App\Modules\Leads\Services\PipelineManager;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Services\ClientWorkspaceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared machinery for the three lightweight tier demo tenants.
 *
 * Each concrete seeder describes one demo tenant (its company, tier plan,
 * channels, volume and conversation topics) through {@see config()}; this base
 * class provisions a self-contained client + admin + workspace, an active
 * subscription on the matching plan, channel accounts, contacts, inbox
 * metadata, pipelines/leads and conversation history.
 *
 * Everything is idempotent (updateOrCreate / firstOrCreate / count guards) so
 * the seeders can run against an existing database as well as a fresh one.
 */
abstract class DemoTenantSeeder extends Seeder
{
    protected int $workspaceId;

    protected Client $client;

    protected User $admin;

    /** @var array<string, ChannelAccount> */
    protected array $channels = [];

    /** @var Collection<int, User> Human agents that conversations can be assigned to. */
    protected Collection $agents;

    /** @var Collection<int, Contact> */
    protected Collection $contacts;

    /**
     * Describe the demo tenant this seeder provisions.
     *
     * @return array{
     *     key: string,
     *     tier: string,
     *     plan: string,
     *     company: string,
     *     email: string,
     *     password: string,
     *     admin_name: string,
     *     display_phone: string,
     *     color: string,
     *     timezone: string,
     *     contacts: int,
     *     leads: int,
     *     conversations: int,
     *     channels: list<string>,
     *     pipelines: list<array{name: string, default: bool}>,
     *     team: list<array{name: string, email: string, role?: string}>,
     *     lead_category: string,
     *     lead_cities: list<string>,
     *     topics: list<array{in: string, out: string}>
     * }
     */
    abstract protected function config(): array;

    public function run(): void
    {
        $cfg = $this->config();

        $this->seedTenant($cfg);
        $this->seedChannels($cfg);
        $this->seedContacts($cfg);
        $this->seedInboxMetadata();
        $this->seedPipelinesAndLeads($cfg);
        $this->seedConversations($cfg);

        $this->command?->info(sprintf(
            '  %-8s %s → login %s / %s (workspace #%d)',
            $cfg['tier'],
            $cfg['company'],
            $cfg['email'],
            $cfg['password'],
            $this->workspaceId,
        ));
    }

    // -------------------------------------------------------------------------
    // Tenant, admin, team, subscription
    // -------------------------------------------------------------------------

    protected function seedTenant(array $cfg): void
    {
        $this->client = Client::updateOrCreate(
            ['email' => $cfg['email']],
            [
                'name' => $cfg['company'],
                'status' => Client::STATUS_ACTIVE,
                'tagline' => $cfg['tagline'] ?? null,
                'primary_color' => $cfg['color'],
                'base_currency' => 'USD',
                'currency_symbol' => '$',
                'currency_position' => 'before',
            ]
        );

        $this->admin = User::updateOrCreate(
            ['email' => $cfg['email']],
            [
                'name' => $cfg['admin_name'],
                'password' => $cfg['password'],
                'role' => User::ROLE_CLIENT,
                'status' => User::STATUS_ACTIVE,
                'client_id' => $this->client->id,
                'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
                'timezone' => $cfg['timezone'],
                'locale' => 'en',
            ]
        );

        if (! $this->admin->hasVerifiedEmail()) {
            $this->admin->markEmailAsVerified();
        }

        app(ClientWorkspaceService::class)->syncClientUser($this->admin->fresh());
        $this->admin = $this->admin->fresh();
        $this->workspaceId = (int) $this->admin->workspace_id;

        Workspace::whereKey($this->workspaceId)->update([
            'name' => $cfg['company'],
            'currency_code' => 'USD',
            'default_locale' => 'en',
        ]);

        $this->attachTierPlan($cfg);

        $this->agents = collect();

        foreach ($cfg['team'] as $member) {
            $user = User::updateOrCreate(
                ['email' => $member['email']],
                [
                    'name' => $member['name'],
                    'password' => $cfg['password'],
                    'role' => User::ROLE_CLIENT,
                    'status' => User::STATUS_ACTIVE,
                    'client_id' => $this->client->id,
                    'client_role' => $member['role'] ?? User::CLIENT_ROLE_STAFF,
                    'workspace_id' => $this->workspaceId,
                    'timezone' => $cfg['timezone'],
                    'locale' => 'en',
                ]
            );

            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            app(ClientWorkspaceService::class)->syncClientUser($user->fresh());
            $this->agents->push($user->fresh());
        }

        // Conversations need at least one human assignee; fall back to the admin.
        if ($this->agents->isEmpty()) {
            $this->agents->push($this->admin);
        }
    }

    /**
     * Put the demo tenant on its tier plan so the UI reflects exactly which
     * features that tier unlocks — the whole point of a tiered sales demo.
     */
    protected function attachTierPlan(array $cfg): void
    {
        $plan = Plan::where('slug', $cfg['plan'])->first();

        if (! $plan) {
            return;
        }

        Subscription::where('user_id', $this->admin->id)
            ->whereIn('status', ['active', 'trialing'])
            ->update(['status' => 'canceled', 'ends_at' => now()]);

        $startsAt = now();

        $this->admin->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'month',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMonth(),
            'gateway' => 'manual',
            'gateway_subscription_id' => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // Channels
    // -------------------------------------------------------------------------

    protected function seedChannels(array $cfg): void
    {
        foreach ($cfg['channels'] as $channel) {
            $this->channels[$channel] = match ($channel) {
                'whatsapp' => $this->seedWhatsappChannel($cfg),
                'instagram' => $this->seedMetaChannel($cfg, 'instagram'),
                'messenger' => $this->seedMetaChannel($cfg, 'messenger'),
                'sms' => $this->seedMetaChannel($cfg, 'sms'),
                'email' => $this->seedEmailChannel($cfg),
                default => null,
            };
        }
    }

    protected function seedWhatsappChannel(array $cfg): ChannelAccount
    {
        $wabaId = 'DEMO_WABA_'.strtoupper($cfg['key']);
        $phoneId = 'DEMO_PHONE_'.strtoupper($cfg['key']);

        $waba = WhatsappBusinessAccount::firstOrCreate(
            ['waba_id' => $wabaId],
            [
                'workspace_id' => $this->workspaceId,
                'credentials' => ['system_user_token' => 'DEMO_TOKEN_'.strtoupper($cfg['key'])],
                'webhook_verify_token' => 'demo-'.$cfg['key'].'-verify-'.Str::random(16),
                'status' => 'active',
                'meta_json' => ['note' => 'Demo account — no real API calls', 'business_name' => $cfg['company']],
            ]
        );

        WhatsappPhoneNumber::firstOrCreate(
            ['phone_number_id' => $phoneId],
            [
                'waba_id_fk' => $waba->id,
                'display_phone' => $cfg['display_phone'],
                'verified_name' => $cfg['company'],
                'quality_rating' => 'GREEN',
                'messaging_limit_tier' => 'TIER_1K',
                'code_verification_status' => 'VERIFIED',
                'name_status' => 'APPROVED',
                'account_mode' => 'LIVE',
            ]
        );

        return ChannelAccount::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'channel' => 'whatsapp', 'phone_number_id' => $phoneId],
            [
                'provider' => 'meta',
                'display_name' => $cfg['company'],
                'business_account_id' => $wabaId,
                'credentials' => ['system_user_token' => 'DEMO_TOKEN_'.strtoupper($cfg['key']), 'phone_number_id' => $phoneId],
                'status' => 'active',
                'meta_json' => ['phone_number_id' => $phoneId, 'verified_name' => $cfg['company']],
            ]
        );
    }

    protected function seedMetaChannel(array $cfg, string $channel): ChannelAccount
    {
        $providerKey = $channel === 'messenger' ? 'page_id' : ($channel === 'instagram' ? 'instagram_account_id' : 'short_code');
        $externalId = 'DEMO_'.strtoupper($cfg['key']).'_'.strtoupper($channel);

        return ChannelAccount::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'channel' => $channel, 'display_name' => $cfg['company']],
            [
                'provider' => $channel === 'sms' ? 'twilio' : 'meta',
                'credentials' => ['access_token' => 'DEMO_TOKEN_'.strtoupper($cfg['key']).'_'.strtoupper($channel)],
                'status' => 'active',
                'meta_json' => [$providerKey => $externalId],
            ]
        );
    }

    protected function seedEmailChannel(array $cfg): ChannelAccount
    {
        return ChannelAccount::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'channel' => 'email', 'display_name' => $cfg['company'].' Support'],
            [
                'provider' => 'smtp',
                'credentials' => ['from_address' => 'support@'.Str::slug($cfg['company'], '').'.test'],
                'status' => 'active',
                'meta_json' => ['from_name' => $cfg['company'].' Support'],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Contacts
    // -------------------------------------------------------------------------

    protected function seedContacts(array $cfg): void
    {
        $people = $this->peoplePool();
        $count = min($cfg['contacts'], count($people));

        $this->contacts = collect();

        for ($i = 0; $i < $count; $i++) {
            [$first, $last] = $people[$i];

            $contact = Contact::firstOrCreate(
                ['workspace_id' => $this->workspaceId, 'phone_e164' => $this->demoPhone($i)],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => Str::slug($first.'.'.$last, '.').'@example.test',
                    'country' => 'US',
                    'language' => 'en',
                    'source' => $cfg['key'].'_demo',
                    'opt_in_whatsapp' => in_array('whatsapp', $cfg['channels'], true),
                    'opt_in_email' => true,
                    'last_seen_at' => $this->days(rand(0, 20)),
                ]
            );

            $this->contacts->push($contact);
        }

        // Standard contact tags, attached to a slice of the book.
        $tagNames = ['Lead', 'Customer', 'VIP', 'Partner'];
        $tags = collect($tagNames)->map(fn (string $name) => ContactTag::firstOrCreate(
            ['workspace_id' => $this->workspaceId, 'name' => $name],
            ['color' => '#6366f1']
        ));

        $this->contacts->each(function (Contact $contact, int $index) use ($tags) {
            $contact->tags()->syncWithoutDetaching([$tags[$index % $tags->count()]->id]);
        });
    }

    // -------------------------------------------------------------------------
    // Inbox metadata
    // -------------------------------------------------------------------------

    protected function seedInboxMetadata(): void
    {
        $labels = [
            'Booking' => '#0E7C5A',
            'Pricing' => '#3b82f6',
            'Follow-up' => '#a855f7',
            'VIP' => '#d97706',
            'Complaint' => '#ef4444',
            'Resolved' => '#6b7280',
        ];

        foreach ($labels as $name => $color) {
            InboxLabel::firstOrCreate(
                ['workspace_id' => $this->workspaceId, 'name' => $name],
                ['color' => $color]
            );
        }

        $canned = [
            ['pricing', 'Happy to help! Let me pull together the right package for you — one moment.'],
            ['thanks', 'Thank you for reaching out — we really appreciate it! 🙌'],
            ['followup', 'Just following up on our last message. Is there anything I can help you with?'],
            ['book', 'You can book directly from your dashboard, or tell me a preferred time and I will reserve it for you.'],
            ['handover', 'Connecting you with a specialist now — they will be with you shortly.'],
        ];

        foreach ($canned as [$shortcut, $body]) {
            CannedReply::firstOrCreate(
                ['workspace_id' => $this->workspaceId, 'shortcut' => $shortcut],
                ['body' => $body]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Pipelines and leads
    // -------------------------------------------------------------------------

    protected function seedPipelinesAndLeads(array $cfg): void
    {
        $manager = app(PipelineManager::class);

        $buckets = [];
        foreach ($cfg['pipelines'] as $sortOrder => $definition) {
            $pipeline = LeadPipeline::firstOrCreate(
                ['workspace_id' => $this->workspaceId, 'name' => $definition['name']],
                ['is_default' => (bool) $definition['default'], 'sort_order' => $definition['default'] ? 0 : $sortOrder]
            );

            $buckets[] = [
                'pipeline' => $pipeline,
                'stages' => $manager->stagesFor($this->workspaceId, $pipeline->id),
            ];
        }

        $tags = collect(['Hot', 'Warm', 'Cold', 'Referral', 'Enterprise'])->map(
            fn (string $name) => LeadTag::firstOrCreate(
                ['workspace_id' => $this->workspaceId, 'name' => $name],
                ['color' => '#0EA5E9']
            )
        );

        $prefixes = ['Summit', 'Blue Ridge', 'Coastal', 'Ironwood', 'Apex', 'Harbor', 'Lakeside', 'Vertex', 'Meridian', 'Crescent', 'Northgate', 'Stonebridge'];

        $stageCounters = Lead::where('workspace_id', $this->workspaceId)
            ->whereNotNull('stage_id')
            ->selectRaw('stage_id, max(board_position) as max_position')
            ->groupBy('stage_id')
            ->pluck('max_position', 'stage_id')
            ->map(fn ($position) => (int) $position + 1)
            ->all();

        // Idempotency guard: google_place_id is globally unique, so a re-run
        // must not try to recreate the same demo leads.
        if (Lead::where('workspace_id', $this->workspaceId)->count() > 0) {
            return;
        }

        for ($i = 0; $i < $cfg['leads']; $i++) {
            $bucket = $buckets[$i % count($buckets)];
            $stages = $bucket['stages'];

            if ($stages->isEmpty()) {
                continue;
            }

            $roll = $i % 100;
            $stage = match (true) {
                $roll <= 40 => $stages->get(0),
                $roll <= 65 => $stages->get(1) ?? $stages->get(0),
                $roll <= 80 => $stages->get(2) ?? $stages->get(0),
                $roll <= 90 => $stages->firstWhere('is_won', true) ?? $stages->get(0),
                default => $stages->firstWhere('is_lost', true) ?? $stages->get(0),
            };

            $position = $stageCounters[$stage->id] ?? 0;
            $stageCounters[$stage->id] = $position + 1;

            $name = Arr::random($prefixes).' '.$cfg['lead_category'];
            $createdAt = $this->days(rand(0, 90))->setTime(rand(8, 18), rand(0, 59));
            $status = match (true) {
                $stage->is_won => 'converted',
                $stage->is_lost => 'lost',
                $stage->name === 'Qualified' => 'qualified',
                $stage->name === 'Contacted' => 'contacted',
                default => 'new',
            };
            $isCompanyLead = rand(1, 100) <= 65;
            $email = $isCompanyLead
                ? 'info@'.Str::slug($name, '').$i.'.example.com'
                : 'lead'.Str::slug($name, '').$i.'@gmail.com';

            $lead = Lead::create([
                'workspace_id' => $this->workspaceId,
                'pipeline_id' => $bucket['pipeline']->id,
                'stage_id' => $stage->id,
                'board_position' => $position,
                'name' => $name,
                'phone' => $this->demoPhone($i + 500),
                'email' => $email,
                'website' => 'https://'.Str::slug($name, '').$i.'.example.com',
                'address' => rand(100, 9999).' '.Arr::random(['Main St', 'Oak Ave', 'Elm St', 'Market St']),
                'city' => Arr::random($cfg['lead_cities']),
                'country' => 'US',
                'category' => $cfg['lead_category'],
                'rating' => round(rand(35, 50) / 10, 1),
                'review_count' => rand(8, 740),
                'google_place_id' => 'DEMO_'.strtoupper($cfg['key']).'_'.$i,
                'whatsapp_status' => Arr::random(['unknown', 'valid', 'valid', 'invalid']),
                'status' => $status,
                'deal_value' => rand(500, 30000),
                'currency' => 'USD',
                'probability' => $stage->default_probability ?? rand(10, 90),
                'expected_close_date' => $stage->isTerminal() ? null : $createdAt->copy()->addDays(rand(10, 90)),
                'won_at' => $stage->is_won ? $createdAt->copy()->addDays(rand(1, 10)) : null,
                'lost_at' => $stage->is_lost ? $createdAt->copy()->addDays(rand(1, 10)) : null,
                'stage_changed_at' => $createdAt,
                'assigned_user_id' => $this->agents->random()->id,
                'assigned_at' => $createdAt,
                'last_activity_at' => $createdAt,
            ]);

            $this->backdate($lead, $createdAt);

            LeadStageHistory::where('lead_id', $lead->id)
                ->whereNull('from_stage_id')
                ->update(['changed_at' => $createdAt]);

            $lead->tags()->syncWithoutDetaching([$tags[$i % $tags->count()]->id]);
        }
    }

    // -------------------------------------------------------------------------
    // Conversations
    // -------------------------------------------------------------------------

    protected function seedConversations(array $cfg): void
    {
        if (Conversation::where('workspace_id', $this->workspaceId)->count() > 0) {
            return;
        }

        if ($this->contacts->isEmpty()) {
            return;
        }

        $statuses = ['open', 'resolved', 'pending', 'snoozed'];
        $channelKeys = array_values(array_filter($cfg['channels'], fn ($c) => isset($this->channels[$c])));

        if ($channelKeys === []) {
            return;
        }

        for ($i = 0; $i < $cfg['conversations']; $i++) {
            $contact = $this->contacts[$i % $this->contacts->count()];
            $channelKey = $channelKeys[$i % count($channelKeys)];
            $status = $statuses[$i % count($statuses)];
            $useBot = $i % 3 === 0;
            $agent = $useBot ? null : $this->agents->random();
            $topic = $cfg['topics'][$i % count($cfg['topics'])];

            $base = $this->days(rand(0, 25))->setTime(rand(9, 18), rand(0, 59));

            $conversation = Conversation::create([
                'workspace_id' => $this->workspaceId,
                'channel_account_id' => $this->channels[$channelKey]->id,
                'contact_id' => $contact->id,
                'external_thread_id' => $channelKey.'_'.Str::random(12),
                'status' => $status,
                'assigned_to' => $useBot ? 'bot' : 'human',
                'assigned_user_id' => $agent?->id,
                'handover_at' => $useBot ? null : $base->copy()->addMinutes(2),
            ]);

            $script = [
                ['in', str_replace('{{name}}', $contact->first_name, $topic['in'])],
                ['out', str_replace('{{name}}', $contact->first_name, $topic['out'])],
            ];

            if (in_array($status, ['resolved', 'pending'], true)) {
                $script[] = ['in', Arr::random(['Perfect, thank you!', 'Great, talk soon.', 'That works for me.'])];
            }

            $cursor = $base->copy();
            $firstInbound = null;
            $firstResponse = null;
            $lastInbound = null;
            $unread = 0;

            foreach ($script as [$direction, $body]) {
                $cursor = $cursor->copy()->addMinutes(rand(2, 26));

                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'direction' => $direction,
                    'channel' => $channelKey,
                    'type' => 'text',
                    'body' => $body,
                    'status' => $direction === 'in' ? 'delivered' : ($status === 'open' ? 'sent' : 'read'),
                    'sent_by' => $direction === 'out' ? ($useBot ? 'bot' : 'human') : 'human',
                    'user_id' => $direction === 'out' ? $agent?->id : null,
                    'provider_message_id' => 'wamid.'.Str::random(28),
                    'sent_at' => $cursor,
                ]);

                $this->backdate($message, $cursor);

                if ($direction === 'in') {
                    $firstInbound ??= $cursor->copy();
                    $lastInbound = $cursor->copy();
                    if ($status === 'open') {
                        $unread++;
                    }
                } elseif ($firstInbound && ! $firstResponse) {
                    $firstResponse = $cursor->copy();
                    $unread = 0;
                }
            }

            $conversation->forceFill([
                'last_message_at' => $cursor,
                'last_inbound_at' => $lastInbound,
                'first_response_at' => $firstResponse,
                'resolved_at' => $status === 'resolved' ? $cursor->copy()->addMinutes(5) : null,
                'unread_count' => $unread,
                'created_at' => $base,
                'updated_at' => $cursor,
            ])->saveQuietly();

            if ($i % 2 === 0 && ($label = InboxLabel::where('workspace_id', $this->workspaceId)->where('name', 'Follow-up')->first())) {
                DB::table('inbox_label_conversation')->insertOrIgnore([
                    'label_id' => $label->id,
                    'conversation_id' => $conversation->id,
                ]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Backdate created_at/updated_at on a freshly-created model for a realistic time-series. */
    protected function backdate($model, Carbon $created, ?Carbon $updated = null): void
    {
        $model->forceFill([
            'created_at' => $created,
            'updated_at' => $updated ?? $created,
        ])->saveQuietly();
    }

    protected function days(int $n): Carbon
    {
        return Carbon::now()->subDays($n);
    }

    /** Deterministic, workspace-unique demo phone number. */
    protected function demoPhone(int $index): string
    {
        return '+1555'.str_pad((string) $index, 7, '0', STR_PAD_LEFT);
    }

    /**
     * A fixed pool of demo people so re-runs produce the same contacts.
     *
     * @return list<array{0: string, 1: string}>
     */
    protected function peoplePool(): array
    {
        return [
            ['Emma', 'Thompson'], ['James', 'Wilson'], ['Sophia', 'Martinez'], ['Liam', 'Anderson'],
            ['Olivia', 'Garcia'], ['Noah', 'Brown'], ['Ava', 'Davis'], ['William', 'Miller'],
            ['Isabella', 'Moore'], ['Mason', 'Taylor'], ['Mia', 'Jackson'], ['Ethan', 'White'],
            ['Charlotte', 'Harris'], ['Logan', 'Martin'], ['Amelia', 'Thompson'], ['Jackson', 'Clark'],
            ['Harper', 'Lewis'], ['Elijah', 'Walker'], ['Evelyn', 'Hall'], ['Oliver', 'Young'],
            ['Abigail', 'King'], ['Lucas', 'Wright'], ['Ella', 'Lopez'], ['Henry', 'Hill'],
            ['Scarlett', 'Scott'], ['Alexander', 'Green'], ['Grace', 'Adams'], ['Sebastian', 'Baker'],
            ['Chloe', 'Nelson'], ['Daniel', 'Carter'],
        ];
    }
}
