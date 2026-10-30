<?php

namespace Tests\Feature\Sales;

use App\Models\Plan;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Sales\Models\LeadSource;
use App\Modules\Sales\Services\ImapLeadParser;
use App\Modules\Sales\Services\LeadAutoCaptureService;
use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeadIngestionChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function actorWithPlan(): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create([
            'feature_flags' => [
                'lead_sources' => true,
                'forms' => true,
            ],
        ]);

        $this->attachPlanToClient($client, $plan);

        return [$user, $workspace, $client];
    }

    #[Test]
    public function whatsapp_inbound_message_auto_captures_lead_and_links_contact(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'John',
            'last_name' => 'Wick',
            'phone_e164' => '+15550001122',
            'opt_in_whatsapp' => true,
            'source' => 'whatsapp_inbound',
        ]);

        $this->assertNull($contact->lead_id);

        $captureService = app(LeadAutoCaptureService::class);
        $lead = $captureService->captureFromWhatsapp(
            $workspace->id,
            $contact,
            '15550001122',
            'Hello, I need information about the Continental suite.',
            conversationId: 42
        );

        $this->assertNotNull($lead);
        $this->assertSame($workspace->id, $lead->workspace_id);
        $this->assertSame('John Wick', $lead->name);
        $this->assertSame('+15550001122', $lead->phone);
        $this->assertSame('whatsapp', $lead->source);

        $contact->refresh();
        $this->assertSame($lead->id, $contact->lead_id);

        // Verify activity logged
        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id,
            'type' => 'note',
        ]);

        // Second message from same contact does NOT duplicate lead
        $lead2 = $captureService->captureFromWhatsapp(
            $workspace->id,
            $contact,
            '15550001122',
            'Following up on my previous message.'
        );

        $this->assertSame($lead->id, $lead2->id);
        $this->assertSame(1, Lead::where('phone', '+15550001122')->count());
    }

    #[Test]
    public function meta_lead_ads_verification_challenge_succeeds(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $source = LeadSource::create([
            'workspace_id' => $workspace->id,
            'name' => 'FB Real Estate Ads',
            'type' => 'meta_ads',
            'status' => 'active',
        ]);

        $response = $this->get(route('webhooks.meta_leadgen.verify', [
            'token' => $source->webhook_token,
            'hub_mode' => 'subscribe',
            'hub_verify_token' => $source->webhook_token,
            'hub_challenge' => 'challenge_code_12345',
        ]));

        $response->assertStatus(200);
        $this->assertSame('challenge_code_12345', $response->getContent());
    }

    #[Test]
    public function meta_lead_ads_webhook_captures_lead_with_utm_attribution(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $source = LeadSource::create([
            'workspace_id' => $workspace->id,
            'name' => 'FB Real Estate Ads',
            'type' => 'meta_ads',
            'status' => 'active',
        ]);

        $payload = [
            'object' => 'page',
            'entry' => [
                [
                    'id' => '109823487192',
                    'time' => 1445827042,
                    'changes' => [
                        [
                            'field' => 'leadgen',
                            'value' => [
                                'ad_id' => 'ad_998877',
                                'form_id' => 'form_554433',
                                'leadgen_id' => 'leadgen_112233',
                                'created_time' => 1445825650,
                                'page_id' => '109823487192',
                                'field_data' => [
                                    ['name' => 'full_name', 'values' => ['Arthur Dent']],
                                    ['name' => 'email', 'values' => ['arthur@galaxy-guide.com']],
                                    ['name' => 'phone_number', 'values' => ['+447700900123']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson(route('webhooks.meta_leadgen.receive', ['token' => $source->webhook_token]), $payload);
        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'leads_count' => 1]);

        $lead = Lead::where('email', 'arthur@galaxy-guide.com')->first();
        $this->assertNotNull($lead);
        $this->assertSame('Arthur Dent', $lead->name);
        $this->assertSame('+447700900123', $lead->phone);
        $this->assertSame('Meta Lead Ads', $lead->source);
        $this->assertSame('facebook', $lead->utm_source);
        $this->assertSame('paid_social', $lead->utm_medium);

        // Activity logged
        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id,
            'type' => 'note',
        ]);

        // Source last_lead_at updated
        $source->refresh();
        $this->assertNotNull($source->last_lead_at);
    }

    #[Test]
    public function imap_email_parser_extracts_structured_lead_data(): void
    {
        $parser = app(ImapLeadParser::class);

        $body = <<<EMAIL
Hello Sales Team,

We are looking for an enterprise CRM subscription for 50 agents.

Full Name: Carlos Santana
Email: carlos@supernatural.com
Phone: +1 555 987 6543
Company: Santana Music Group

Please send us a proposal.
EMAIL;

        $parsed = $parser->parseEmail($body, 'Enterprise Quote Request', 'inquiries@website.com', 'Website Inquiry');

        $this->assertSame('Carlos Santana', $parsed['name']);
        $this->assertSame('carlos@supernatural.com', $parsed['email']);
        $this->assertSame('+1 555 987 6543', $parsed['phone']);
        $this->assertSame('Enterprise Quote Request', $parsed['subject']);
    }

    #[Test]
    public function imap_lead_ingestion_creates_lead_and_activity(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $source = LeadSource::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support Email Ingestion',
            'type' => 'imap',
            'status' => 'active',
        ]);

        $parser = app(ImapLeadParser::class);
        $lead = $parser->ingest($workspace->id, [
            'body' => "Interested in your CRM software.\nName: Ellen Ripley\nPhone: +1 555 432 1098\nEmail: ripley@weyland.com",
            'subject' => 'Product Demo Inquiry',
            'from_email' => 'ripley@weyland.com',
            'from_name' => 'Ellen Ripley',
        ], $source);

        $this->assertNotNull($lead);
        $this->assertSame($workspace->id, $lead->workspace_id);
        $this->assertSame('Ellen Ripley', $lead->name);
        $this->assertSame('ripley@weyland.com', $lead->email);
        $this->assertSame('+1 555 432 1098', $lead->phone);
        $this->assertSame('email_imap', $lead->source);

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id,
            'type' => 'note',
        ]);
    }

    #[Test]
    public function fetch_imap_leads_artisan_command_runs_successfully(): void
    {
        $exitCode = Artisan::call('leads:fetch-imap');
        $this->assertSame(0, $exitCode);
    }
}

