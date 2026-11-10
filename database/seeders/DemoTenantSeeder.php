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
        $this->seedShowcase($cfg);

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
                    'workspace_id' => $this->workspaceId,
                ]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Showcase data — populated screens for demos and documentation screenshots
    // -------------------------------------------------------------------------

    /**
     * Fill the screens that would otherwise show an empty state (segments,
     * catalogue, quotes, invoices, campaigns, automations, templates, auto
     * replies, AI, social, tasks, calls, booking and forms).
     *
     * Written through the query builder on purpose: this is display-only demo
     * data, so it must not fire domain events (no emails, no automations, no
     * AI calls) the way creating the models through their services would.
     */
    protected function seedShowcase(array $cfg): void
    {
        $ws = $this->workspaceId;
        $now = Carbon::now();
        $contactIds = $this->contacts->pluck('id')->values()->all();
        $leadIds = Lead::where('workspace_id', $ws)->orderBy('id')->pluck('id')->values()->all();
        $agentIds = $this->agents->pluck('id')->values()->all() ?: [$this->admin->id];

        $this->seedSegments($ws, $contactIds, $now);
        $products = $this->seedProducts($ws, $now);
        $this->seedQuotes($ws, $leadIds, $products, $now);
        $this->seedInvoices($ws, $leadIds, $products, $now);
        $this->seedSequences($ws, $now);
        $this->seedCampaigns($ws, $contactIds, $now);
        $this->seedAutomations($ws, $now);
        $this->seedWhatsappShowcase($cfg, $ws, $now);
        $this->seedAiShowcase($ws, $now);
        $this->seedSocialShowcase($ws, $agentIds, $now);
        $this->seedTasksAndCalls($ws, $leadIds, $agentIds, $now);
        $this->seedBookingAndForms($ws, $now);
    }

    /** @param list<int> $contactIds */
    protected function seedSegments(int $ws, array $contactIds, Carbon $now): void
    {
        if ($contactIds === []) {
            return;
        }

        $segments = [
            ['VIP customers', 6],
            ['Newsletter subscribers', 18],
            ['Recent WhatsApp leads', 12],
        ];

        foreach ($segments as [$name, $size]) {
            DB::table('segments')->updateOrInsert(
                ['workspace_id' => $ws, 'name' => $name],
                ['type' => 'static', 'rules_json' => null, 'contact_count' => min($size, count($contactIds)), 'created_at' => $now, 'updated_at' => $now]
            );

            $segmentId = (int) DB::table('segments')->where('workspace_id', $ws)->where('name', $name)->value('id');
            $members = array_slice($contactIds, 0, min($size, count($contactIds)));

            DB::table('segment_contact')->where('segment_id', $segmentId)->delete();
            foreach ($members as $contactId) {
                DB::table('segment_contact')->insertOrIgnore([
                    'segment_id' => $segmentId,
                    'contact_id' => $contactId,
                    'workspace_id' => $ws,
                ]);
            }
        }
    }

    /**
     * @return array<string, array{id: int, price: float}>
     */
    protected function seedProducts(int $ws, Carbon $now): array
    {
        $catalogue = [
            ['Annual Membership', 'MEM-ANNUAL', 890.00, 'year', 'Unlimited studio access for one member for twelve months.'],
            ['Corporate Plan (per seat)', 'CORP-SEAT', 29.00, 'month', 'Per-employee corporate membership with a dedicated account manager.'],
            ['Personal Training Pack (10)', 'PT-10', 450.00, 'pack', 'Ten one-hour personal training sessions, bookable in the app.'],
            ['On-site Class', 'SITE-CLASS', 220.00, 'session', 'A coached HIIT or yoga session delivered at your office.'],
        ];

        $products = [];

        foreach ($catalogue as [$name, $sku, $price, $unit, $description]) {
            DB::table('products')->updateOrInsert(
                ['workspace_id' => $ws, 'name' => $name],
                [
                    'sku' => $sku,
                    'price' => $price,
                    'currency' => 'USD',
                    'tax_rate' => 0,
                    'unit' => $unit,
                    'description' => $description,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $products[$name] = [
                'id' => (int) DB::table('products')->where('workspace_id', $ws)->where('name', $name)->value('id'),
                'price' => (float) $price,
            ];
        }

        return $products;
    }

    /**
     * @param  list<int>  $leadIds
     * @param  array<string, array{id: int, price: float}>  $products
     */
    protected function seedQuotes(int $ws, array $leadIds, array $products, Carbon $now): void
    {
        $definitions = [
            ['Q-2026-0001', 'sent', [['Corporate Plan (per seat)', 50], ['On-site Class', 2]]],
            ['Q-2026-0002', 'accepted', [['Annual Membership', 4]]],
            ['Q-2026-0003', 'draft', [['Personal Training Pack (10)', 3]]],
        ];

        foreach ($definitions as $index => [$number, $status, $lines]) {
            $subtotal = 0.0;
            foreach ($lines as [$name, $qty]) {
                $subtotal += ($products[$name]['price'] ?? 0) * $qty;
            }
            $total = round($subtotal, 2);
            $createdAt = $this->days(40 - $index * 8);

            DB::table('quotes')->updateOrInsert(
                ['workspace_id' => $ws, 'number' => $number],
                [
                    'uuid' => (string) Str::uuid(),
                    'lead_id' => $leadIds[$index] ?? null,
                    'status' => $status,
                    'valid_until' => $createdAt->copy()->addDays(30)->toDateString(),
                    'subtotal' => $total,
                    'discount_value' => 0,
                    'tax_total' => 0,
                    'total' => $total,
                    'currency' => 'USD',
                    'notes' => 'Prices valid for 30 days. Training sessions are scheduled after signing.',
                    'terms' => 'Payment by bank transfer within 14 days of acceptance.',
                    'public_token' => Str::random(48),
                    'sent_at' => $status === 'draft' ? null : $createdAt,
                    'accepted_at' => $status === 'accepted' ? $createdAt->copy()->addDays(3) : null,
                    'created_by' => $this->admin->id,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]
            );

            $quoteId = (int) DB::table('quotes')->where('workspace_id', $ws)->where('number', $number)->value('id');

            DB::table('quote_items')->where('quote_id', $quoteId)->delete();
            foreach ($lines as $position => [$name, $qty]) {
                $unit = $products[$name]['price'] ?? 0;
                DB::table('quote_items')->insert([
                    'quote_id' => $quoteId,
                    'product_id' => $products[$name]['id'] ?? null,
                    'description' => $name,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'tax_rate' => 0,
                    'total' => round($unit * $qty, 2),
                    'sort_order' => $position,
                ]);
            }
        }
    }

    /**
     * @param  list<int>  $leadIds
     * @param  array<string, array{id: int, price: float}>  $products
     */
    protected function seedInvoices(int $ws, array $leadIds, array $products, Carbon $now): void
    {
        $definitions = [
            ['INV-2026-0001', 'paid', [['Annual Membership', 2]], 30],
            ['INV-2026-0002', 'sent', [['Corporate Plan (per seat)', 25]], 12],
            ['INV-2026-0003', 'overdue', [['On-site Class', 4]], 45],
        ];

        foreach ($definitions as $index => [$number, $status, $lines, $ageDays]) {
            $subtotal = 0.0;
            foreach ($lines as [$name, $qty]) {
                $subtotal += ($products[$name]['price'] ?? 0) * $qty;
            }
            $total = round($subtotal, 2);
            $createdAt = $this->days($ageDays);

            DB::table('invoices')->updateOrInsert(
                ['workspace_id' => $ws, 'number' => $number],
                [
                    'uuid' => (string) Str::uuid(),
                    'lead_id' => $leadIds[$index] ?? null,
                    'status' => $status,
                    'subtotal' => $total,
                    'tax_total' => 0,
                    'total' => $total,
                    'currency' => 'USD',
                    'due_date' => $createdAt->copy()->addDays(14)->toDateString(),
                    'paid_at' => $status === 'paid' ? $createdAt->copy()->addDays(6) : null,
                    'notes' => 'Thank you for your business.',
                    'public_token' => Str::random(48),
                    'created_by' => $this->admin->id,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]
            );
        }
    }

    protected function seedSequences(int $ws, Carbon $now): void
    {
        $definitions = [
            'New lead welcome' => [
                ['email', 0, 'Welcome aboard', 'Hi {{first_name}}, thanks for reaching out — here is what happens next.'],
                ['email', 48, 'A quick question', 'Do you have five minutes this week for a short call?'],
                ['whatsapp', 72, null, 'Just checking in, {{first_name}} — happy to help with anything.'],
            ],
            'Trial follow-up' => [
                ['email', 24, 'How is your trial going?', 'We would love to hear how the first day went.'],
                ['email', 96, 'Your trial ends soon', 'Your trial ends in three days — shall we extend it?'],
            ],
        ];

        foreach ($definitions as $name => $steps) {
            DB::table('sequences')->updateOrInsert(
                ['workspace_id' => $ws, 'name' => $name],
                [
                    'uuid' => (string) Str::uuid(),
                    'source' => 'user',
                    'status' => 'active',
                    'stop_on_reply' => 1,
                    'stop_on_won' => 1,
                    'respect_hours' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $sequenceId = (int) DB::table('sequences')->where('workspace_id', $ws)->where('name', $name)->value('id');

            DB::table('sequence_steps')->where('sequence_id', $sequenceId)->delete();
            foreach ($steps as $position => [$channel, $delay, $subject, $body]) {
                DB::table('sequence_steps')->insert([
                    'sequence_id' => $sequenceId,
                    'position' => $position,
                    'channel' => $channel,
                    'delay_hours' => $delay,
                    'subject' => $subject,
                    'body' => $body,
                    'condition' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /** @param list<int> $contactIds */
    protected function seedCampaigns(int $ws, array $contactIds, Carbon $now): void
    {
        $definitions = [
            [
                'name' => 'Autumn Promo — WhatsApp',
                'channel' => 'whatsapp',
                'status' => 'completed',
                'totals' => ['total' => 30, 'sent' => 30, 'delivered' => 29, 'read' => 24, 'replied' => 6, 'failed' => 1],
                'age' => 12,
                'fill_recipients' => true,
            ],
            [
                'name' => 'October Newsletter — Email',
                'channel' => 'email',
                'status' => 'completed',
                'totals' => ['total' => 30, 'sent' => 30, 'delivered' => 30, 'read' => 18, 'replied' => 3, 'failed' => 0],
                'age' => 25,
                'fill_recipients' => true,
            ],
            [
                'name' => 'Black Friday Preview',
                'channel' => 'whatsapp',
                'status' => 'draft',
                'totals' => null,
                'age' => 1,
                'fill_recipients' => false,
            ],
        ];

        foreach ($definitions as $definition) {
            $createdAt = $this->days($definition['age']);

            DB::table('campaigns')->updateOrInsert(
                ['workspace_id' => $ws, 'name' => $definition['name']],
                [
                    'uuid' => (string) Str::uuid(),
                    'channel' => $definition['channel'],
                    'audience_type' => 'segment',
                    'audience_ref' => 'VIP customers',
                    'template_ref' => json_encode(['name' => 'autumn_promo', 'language' => 'en']),
                    'payload_json' => json_encode(['body' => 'Autumn promo: 20% off annual memberships until Friday.']),
                    'schedule_at' => $createdAt,
                    'timezone' => 'UTC',
                    'status' => $definition['status'],
                    'totals_json' => $definition['totals'] === null ? null : json_encode($definition['totals']),
                    'created_by' => $this->admin->id,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]
            );

            if (! $definition['fill_recipients']) {
                continue;
            }

            $campaignId = (int) DB::table('campaigns')->where('workspace_id', $ws)->where('name', $definition['name'])->value('id');

            if (DB::table('campaign_recipients')->where('campaign_id', $campaignId)->exists()) {
                continue;
            }

            $statuses = ['read', 'read', 'read', 'delivered', 'delivered', 'sent', 'failed'];

            foreach (array_slice($contactIds, 0, 30) as $index => $contactId) {
                DB::table('campaign_recipients')->insert([
                    'campaign_id' => $campaignId,
                    'contact_id' => $contactId,
                    'status' => $statuses[$index % count($statuses)],
                    'tracking_token' => Str::random(32),
                    'unsubscribe_token' => Str::random(32),
                    'sent_at' => $createdAt,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }
        }
    }

    protected function seedAutomations(int $ws, Carbon $now): void
    {
        $definitions = [
            [
                'name' => 'Welcome new WhatsApp leads',
                'status' => 'active',
                'trigger' => 'contact.created',
                'steps' => ['Send a WhatsApp welcome message', 'Wait 1 day', 'Add the “Follow-up” label'],
                'runs' => 148,
            ],
            [
                'name' => 'Notify the team when a deal is won',
                'status' => 'active',
                'trigger' => 'lead.won',
                'steps' => ['Send an internal email', 'Create a follow-up task'],
                'runs' => 37,
            ],
            [
                'name' => 'Re-engage leads with no reply',
                'status' => 'paused',
                'trigger' => 'lead.inactive',
                'steps' => ['Wait 3 days', 'Send a WhatsApp nudge', 'Mark the lead as cold'],
                'runs' => 12,
            ],
        ];

        foreach ($definitions as $index => $definition) {
            $nodes = [[
                'id' => 'trigger-1',
                'type' => 'trigger',
                'position' => ['x' => 250, 'y' => 40],
                'data' => ['label' => 'Trigger', 'triggerType' => $definition['trigger']],
            ]];

            foreach ($definition['steps'] as $stepIndex => $label) {
                $nodes[] = [
                    'id' => 'node-'.($stepIndex + 1),
                    'type' => 'action',
                    'position' => ['x' => 250, 'y' => 160 + $stepIndex * 120],
                    'data' => ['label' => $label],
                ];
            }

            $edges = [];
            for ($i = 0; $i < count($nodes) - 1; $i++) {
                $edges[] = ['id' => 'edge-'.$i, 'source' => $nodes[$i]['id'], 'target' => $nodes[$i + 1]['id']];
            }

            DB::table('automations')->updateOrInsert(
                ['workspace_id' => $ws, 'name' => $definition['name']],
                [
                    'uuid' => (string) Str::uuid(),
                    'status' => $definition['status'],
                    'trigger_type' => $definition['trigger'],
                    'trigger_config' => json_encode(['source' => 'all']),
                    'trigger_token' => Str::random(32),
                    'nodes' => json_encode($nodes),
                    'edges' => json_encode($edges),
                    'run_count' => $definition['runs'],
                    'created_at' => $this->days(60 - $index * 10),
                    'updated_at' => $this->days(2),
                ]
            );
        }
    }

    protected function seedWhatsappShowcase(array $cfg, int $ws, Carbon $now): void
    {
        $wabaId = 'DEMO_WABA_'.strtoupper($cfg['key']);
        $channel = $this->channels['whatsapp'] ?? null;

        $templates = [
            ['autumn_promo', 'MARKETING', 'APPROVED', 'Autumn promo — 20% off annual memberships'],
            ['order_update', 'UTILITY', 'APPROVED', 'Your order has shipped'],
            ['booking_reminder', 'UTILITY', 'PENDING', 'Reminder: your session starts in one hour'],
            ['winback_offer', 'MARKETING', 'REJECTED', 'We miss you — here is 30% off'],
        ];

        foreach ($templates as [$name, $category, $status, $body]) {
            DB::table('whatsapp_templates')->updateOrInsert(
                ['workspace_id' => $ws, 'waba_id' => $wabaId, 'name' => $name, 'language' => 'en'],
                [
                    'category' => $category,
                    'status' => $status,
                    'components' => json_encode([
                        ['type' => 'BODY', 'text' => $body],
                    ]),
                    'rejection_reason' => $status === 'REJECTED' ? 'Template content is too promotional for a utility category.' : null,
                    'meta_template_id' => 'DEMO_TPL_'.strtoupper($name),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        if (! $channel) {
            return;
        }

        $autoReplies = [
            ['pricing', ['price', 'pricing', 'cost', 'how much'], 'Our plans start at $29 per seat per month — would you like the full price list?'],
            ['hours', ['hours', 'open', 'closing'], 'We are open Monday to Saturday, 6am to 10pm.'],
        ];

        foreach ($autoReplies as [$suffix, $keywords, $reply]) {
            DB::table('whatsapp_auto_replies')->updateOrInsert(
                ['workspace_id' => $ws, 'channel_account_id' => $channel->id, 'trigger_type' => 'keyword', 'keywords' => json_encode($keywords)],
                [
                    'match_mode' => 'contains',
                    'schedule_json' => null,
                    'response_kind' => 'text',
                    'payload_json' => json_encode(['text' => $reply]),
                    'enabled' => 1,
                    'priority' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    protected function seedAiShowcase(int $ws, Carbon $now): void
    {
        DB::table('ai_knowledge_bases')->updateOrInsert(
            ['workspace_id' => $ws, 'name' => 'Product & Pricing FAQ'],
            [
                'uuid' => (string) Str::uuid(),
                'embedding_model' => 'text-embedding-3-small',
                'dimensions' => 1536,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $kbId = (int) DB::table('ai_knowledge_bases')->where('workspace_id', $ws)->where('name', 'Product & Pricing FAQ')->value('id');

        DB::table('ai_chatbots')->updateOrInsert(
            ['workspace_id' => $ws, 'name' => 'Sales Assistant'],
            [
                'uuid' => (string) Str::uuid(),
                'ai_kb_id' => $kbId,
                'system_prompt' => 'You are the AYDRA sales assistant. Answer pricing and membership questions using the knowledge base, and hand over to a human when the customer asks to buy.',
                'tone' => 'friendly',
                'max_context_chunks' => 5,
                'fallback_reply' => 'Let me connect you with a teammate who can help with that.',
                'channels' => json_encode(['whatsapp']),
                'enabled' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    /** @param list<int> $agentIds */
    protected function seedSocialShowcase(int $ws, array $agentIds, Carbon $now): void
    {
        $userId = $agentIds[0] ?? $this->admin->id;

        foreach ([['facebook', '360 Business'], ['instagram', '@360business']] as [$provider, $handle]) {
            DB::table('social_accounts')->updateOrInsert(
                ['user_id' => $userId, 'provider' => $provider],
                [
                    'provider_id' => 'DEMO_'.strtoupper($provider).'_360',
                    'email' => 'social@360business.test',
                    'avatar_url' => null,
                    'access_token' => null,
                    'refresh_token' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $posts = [
            ['Autumn membership offer', 'Twenty percent off annual memberships until Friday. Book a tour this week and see the studio for yourself.', 'published', 6],
            ['New HIIT class timetable', 'Six new HIIT slots added from Monday, including two early-morning sessions.', 'scheduled', -3],
            ['Why corporate wellness pays off', 'A short look at what changed for three of our corporate clients this year.', 'draft', null],
        ];

        foreach ($posts as [$title, $body, $status, $ageDays]) {
            DB::table('social_media_posts')->updateOrInsert(
                ['workspace_id' => $ws, 'title' => $title],
                [
                    'body' => $body,
                    'media_urls' => json_encode([]),
                    'target_accounts' => json_encode(['facebook', 'instagram']),
                    'status' => $status,
                    'scheduled_at' => $ageDays === null ? null : ($ageDays < 0 ? $now->copy()->addDays(abs($ageDays)) : $this->days($ageDays)),
                    'timezone' => 'UTC',
                    'published_at' => $status === 'published' ? $this->days($ageDays ?? 1) : null,
                    'provider_post_id' => $status === 'published' ? 'DEMO_POST_'.Str::random(10) : null,
                    'post_url' => null,
                    'publish_results' => null,
                    'ai_generated' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    /**
     * @param  list<int>  $leadIds
     * @param  list<int>  $agentIds
     */
    protected function seedTasksAndCalls(int $ws, array $leadIds, array $agentIds, Carbon $now): void
    {
        if ($leadIds === []) {
            return;
        }

        $tasks = [
            ['Send the corporate proposal', 'high', 2, false],
            ['Call back about the annual plan', 'medium', 0, false],
            ['Confirm the studio tour', 'low', 5, true],
            ['Share the training schedule', 'medium', -2, false],
        ];

        foreach ($tasks as $index => [$title, $priority, $dueInDays, $completed]) {
            DB::table('lead_tasks')->updateOrInsert(
                ['workspace_id' => $ws, 'lead_id' => $leadIds[$index] ?? $leadIds[0], 'title' => $title],
                [
                    'assigned_user_id' => $agentIds[$index % count($agentIds)],
                    'created_by' => $this->admin->id,
                    'description' => 'Created from the demo showcase seed.',
                    'type' => 'todo',
                    'priority' => $priority,
                    'due_at' => $now->copy()->addDays($dueInDays),
                    'completed_at' => $completed ? $now->copy()->subDay() : null,
                    'reminded_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $calls = [
            ['outbound', 'answered', 412, 'Discussed corporate pricing for 50 seats.'],
            ['outbound', 'no_answer', 0, 'Tried twice, no answer.'],
            ['inbound', 'completed', 168, 'Customer asked about the annual plan and trial.'],
        ];

        foreach ($calls as $index => [$direction, $status, $duration, $notes]) {
            DB::table('call_logs')->updateOrInsert(
                ['workspace_id' => $ws, 'lead_id' => $leadIds[$index] ?? $leadIds[0], 'notes' => $notes],
                [
                    'user_id' => $agentIds[$index % count($agentIds)],
                    'direction' => $direction,
                    'from_number' => $direction === 'outbound' ? '+15550199' : '+15550001',
                    'to_number' => $direction === 'outbound' ? '+15550001' : '+15550199',
                    'duration_secs' => $duration,
                    'status' => $status,
                    'recording_url' => null,
                    'transcript' => null,
                    'summary' => null,
                    'summary_data' => null,
                    'summary_generated_at' => null,
                    'provider' => 'demo',
                    'provider_sid' => null,
                    'created_at' => $this->days(3 + $index),
                    'updated_at' => $this->days(3 + $index),
                ]
            );
        }
    }

    protected function seedBookingAndForms(int $ws, Carbon $now): void
    {
        $meetings = [
            ['Discovery call', 'discovery-call', 30, 'google_meet', 'A short call to understand your goals.'],
            ['Studio tour', 'studio-tour', 45, 'in_person', 'Walk through the studio with a membership advisor.'],
        ];

        foreach ($meetings as [$name, $slug, $duration, $location, $description]) {
            DB::table('meeting_types')->updateOrInsert(
                ['workspace_id' => $ws, 'slug' => $slug],
                [
                    'uuid' => (string) Str::uuid(),
                    'user_id' => $this->admin->id,
                    'name' => $name,
                    'description' => $description,
                    'duration_minutes' => $duration,
                    'buffer_before' => 0,
                    'buffer_after' => 10,
                    'location_type' => $location,
                    'location_details' => $location === 'in_person' ? 'Main studio reception' : null,
                    'max_per_day' => null,
                    'min_notice_hours' => 4,
                    'booking_window_days' => 21,
                    'color' => '#f59e0b',
                    'questions' => json_encode([]),
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        DB::table('forms')->updateOrInsert(
            ['workspace_id' => $ws, 'slug' => 'corporate-enquiry'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Corporate enquiry',
                'status' => 'published',
                'pipeline_id' => null,
                'target_stage_id' => null,
                'assignment_mode' => 'round_robin',
                'assigned_user_id' => null,
                'auto_tags' => json_encode(['Corporate']),
                'thank_you_type' => 'message',
                'thank_you_value' => 'Thanks! A membership advisor will contact you within one business day.',
                'spam_protection' => 'none',
                'settings' => json_encode([]),
                'views_count' => 214,
                'submissions_count' => 18,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $formId = (int) DB::table('forms')->where('workspace_id', $ws)->where('slug', 'corporate-enquiry')->value('id');

        DB::table('form_fields')->where('form_id', $formId)->delete();

        $fields = [
            ['text', 'Full name', 'full_name', true, 'name'],
            ['email', 'Work email', 'work_email', true, 'email'],
            ['text', 'Company', 'company', true, 'company'],
            ['number', 'Number of employees', 'employees', false, null],
            ['textarea', 'What are you looking for?', 'message', false, null],
        ];

        foreach ($fields as $position => [$type, $label, $key, $required, $mapsTo]) {
            DB::table('form_fields')->insert([
                'form_id' => $formId,
                'type' => $type,
                'label' => $label,
                'field_key' => $key,
                'placeholder' => null,
                'help_text' => null,
                'required' => $required ? 1 : 0,
                'options' => null,
                'maps_to' => $mapsTo,
                'sort_order' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
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
