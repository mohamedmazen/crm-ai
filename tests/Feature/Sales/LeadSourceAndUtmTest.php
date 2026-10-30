<?php

namespace Tests\Feature\Sales;

use App\Models\Plan;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Leads\Services\PipelineManager;
use App\Modules\Sales\Models\Form;
use App\Modules\Sales\Models\FormField;
use App\Modules\Sales\Models\LeadSource;
use App\Modules\Sales\Services\FormSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeadSourceAndUtmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function actorWithPlan(mixed $sourcesFeature = true): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create([
            'feature_flags' => [
                'lead_sources' => $sourcesFeature,
                'forms' => true,
                'form_submissions' => 5000,
            ],
        ]);

        $this->attachPlanToClient($client, $plan);

        return [$user, $workspace, $client];
    }

    #[Test]
    public function leads_table_has_utm_and_source_attribution_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('leads', 'source'));
        $this->assertTrue(Schema::hasColumn('leads', 'lead_source_id'));
        $this->assertTrue(Schema::hasColumn('leads', 'utm_source'));
        $this->assertTrue(Schema::hasColumn('leads', 'utm_medium'));
        $this->assertTrue(Schema::hasColumn('leads', 'utm_campaign'));
        $this->assertTrue(Schema::hasColumn('leads', 'utm_term'));
        $this->assertTrue(Schema::hasColumn('leads', 'utm_content'));
        $this->assertTrue(Schema::hasColumn('leads', 'referrer'));
        $this->assertTrue(Schema::hasColumn('leads', 'landing_page'));
    }

    #[Test]
    public function lead_sources_feature_gate_blocks_when_disabled(): void
    {
        [$user] = $this->actorWithPlan(sourcesFeature: false);

        $response = $this->actingAs($user)->get(route('client.sales.lead-sources.index'));
        $response->assertRedirect(route('client.pricing'));
        $response->assertSessionHas('upgrade_required', true);
    }

    #[Test]
    public function client_can_view_lead_sources_index_and_seeds_defaults_if_empty(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $response = $this->actingAs($user)->get(route('client.sales.lead-sources.index'));
        $response->assertStatus(200);

        // Assert default standard channels were initialized
        $this->assertDatabaseHas('lead_sources', [
            'workspace_id' => $workspace->id,
            'type' => LeadSource::TYPE_FORM,
        ]);
        $this->assertDatabaseHas('lead_sources', [
            'workspace_id' => $workspace->id,
            'type' => LeadSource::TYPE_WEBHOOK,
        ]);
    }

    #[Test]
    public function client_can_create_custom_webhook_lead_source(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $response = $this->actingAs($user)->post(route('client.sales.lead-sources.store'), [
            'name' => 'TikTok Ads Webhook',
            'type' => 'webhook',
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('lead_sources', [
            'workspace_id' => $workspace->id,
            'name' => 'TikTok Ads Webhook',
            'type' => 'webhook',
            'status' => 'active',
        ]);

        $source = LeadSource::where('name', 'TikTok Ads Webhook')->first();
        $this->assertNotNull($source->webhook_token);
        $this->assertNotNull($source->webhook_url);
    }

    #[Test]
    public function client_can_update_lead_source_and_regenerate_token(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $source = LeadSource::create([
            'workspace_id' => $workspace->id,
            'name' => 'Original Name',
            'type' => 'webhook',
            'status' => 'active',
        ]);

        $oldToken = $source->webhook_token;

        // Update name and status
        $response = $this->actingAs($user)->put(route('client.sales.lead-sources.update', $source->id), [
            'name' => 'Updated Name',
            'status' => 'inactive',
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('lead_sources', [
            'id' => $source->id,
            'name' => 'Updated Name',
            'status' => 'inactive',
        ]);

        // Regenerate Token
        $responseToken = $this->actingAs($user)->post(route('client.sales.lead-sources.regenerate-token', $source->id));
        $responseToken->assertRedirect();

        $source->refresh();
        $this->assertNotSame($oldToken, $source->webhook_token);
    }

    #[Test]
    public function client_can_delete_lead_source(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $source = LeadSource::create([
            'workspace_id' => $workspace->id,
            'name' => 'Temporary Source',
            'type' => 'webhook',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->delete(route('client.sales.lead-sources.destroy', $source->id));
        $response->assertRedirect();
        $this->assertDatabaseMissing('lead_sources', ['id' => $source->id]);
    }

    #[Test]
    public function inbound_webhook_creates_lead_with_utm_attribution(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $source = LeadSource::create([
            'workspace_id' => $workspace->id,
            'name' => 'Landing Page Webhook',
            'type' => 'webhook',
            'status' => 'active',
        ]);

        $payload = [
            'name' => 'Sarah Connor',
            'email' => 'sarah@skynet-resistance.com',
            'phone' => '+15559876543',
            'deal_value' => 7500,
            'utm_source' => 'google_ads',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'cyberdyne_hunt',
            'utm_term' => 'terminator protection',
            'utm_content' => 'hero_button',
            'landing_page' => 'https://landing.site/protect',
            'message' => 'Need security consultation immediately',
        ];

        $response = $this->postJson(route('webhooks.lead_source.receive', $source->webhook_token), $payload);
        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
        ]);

        $leadId = $response->json('lead_id');
        $lead = Lead::find($leadId);

        $this->assertNotNull($lead);
        $this->assertSame($workspace->id, $lead->workspace_id);
        $this->assertSame('Sarah Connor', $lead->name);
        $this->assertSame('sarah@skynet-resistance.com', $lead->email);
        $this->assertSame('+15559876543', $lead->phone);
        $this->assertEquals(7500.0, (float) $lead->deal_value);
        $this->assertSame('Landing Page Webhook', $lead->source);
        $this->assertSame($source->id, $lead->lead_source_id);
        $this->assertSame('google_ads', $lead->utm_source);
        $this->assertSame('cpc', $lead->utm_medium);
        $this->assertSame('cyberdyne_hunt', $lead->utm_campaign);
        $this->assertSame('terminator protection', $lead->utm_term);
        $this->assertSame('hero_button', $lead->utm_content);
        $this->assertSame('https://landing.site/protect', $lead->landing_page);

        // Verify activity was created for message
        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id,
            'type' => 'note',
        ]);

        // Verify source last_lead_at was updated
        $source->refresh();
        $this->assertNotNull($source->last_lead_at);
    }

    #[Test]
    public function inbound_webhook_rejects_invalid_token_with_404(): void
    {
        $response = $this->postJson(route('webhooks.lead_source.receive', 'non-existent-token'), [
            'name' => 'Tester',
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(404);
        $response->assertJson(['success' => false, 'error' => 'Invalid webhook token']);
    }

    #[Test]
    public function inbound_webhook_rejects_inactive_source_with_403(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $source = LeadSource::create([
            'workspace_id' => $workspace->id,
            'name' => 'Paused Source',
            'type' => 'webhook',
            'status' => 'inactive',
        ]);

        $response = $this->postJson(route('webhooks.lead_source.receive', $source->webhook_token), [
            'name' => 'Tester',
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['success' => false, 'error' => 'Lead source is not active']);
    }

    #[Test]
    public function inbound_webhook_rejects_empty_contact_data_with_422(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $source = LeadSource::create([
            'workspace_id' => $workspace->id,
            'name' => 'Active Source',
            'type' => 'webhook',
            'status' => 'active',
        ]);

        $response = $this->postJson(route('webhooks.lead_source.receive', $source->webhook_token), [
            'deal_value' => 500,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'error' => 'Validation error']);
    }

    #[Test]
    public function form_submission_propagates_source_and_utm_attribution_to_lead(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $form = Form::create([
            'workspace_id' => $workspace->id,
            'name' => 'Contact Us Form',
            'slug' => 'contact-us-form',
            'status' => 'published',
        ]);

        FormField::create([
            'form_id' => $form->id,
            'type' => 'text',
            'label' => 'Full Name',
            'field_key' => 'full_name',
            'maps_to' => 'name',
            'required' => true,
        ]);

        FormField::create([
            'form_id' => $form->id,
            'type' => 'email',
            'label' => 'Email Address',
            'field_key' => 'email_address',
            'maps_to' => 'email',
            'required' => true,
        ]);

        $service = app(FormSubmissionService::class);
        $result = $service->processSubmission(
            $form,
            ['full_name' => 'Alex Murphy', 'email_address' => 'alex@ocp.com'],
            [
                'utm_source' => 'linkedin',
                'utm_medium' => 'social_paid',
                'utm_campaign' => 'detroit_safety',
                'referrer' => 'https://linkedin.com/feed',
            ]
        );

        $lead = Lead::find($result['lead_id']);
        $this->assertNotNull($lead);
        $this->assertSame('Form: Contact Us Form', $lead->source);
        $this->assertSame('linkedin', $lead->utm_source);
        $this->assertSame('social_paid', $lead->utm_medium);
        $this->assertSame('detroit_safety', $lead->utm_campaign);
        $this->assertSame('https://linkedin.com/feed', $lead->referrer);

        // Lead source was associated
        $this->assertNotNull($lead->lead_source_id);
    }

    #[Test]
    public function tracker_js_route_serves_valid_javascript(): void
    {
        $response = $this->get(route('tracker.script'));
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/javascript');
        $this->assertStringContainsString('WhatsMineTracker', $response->getContent());
        $this->assertStringContainsString('_wm_utm', $response->getContent());
    }

    #[Test]
    public function workspace_isolation_for_lead_sources(): void
    {
        [$user1, $workspace1] = $this->actorWithPlan();
        [$user2, $workspace2] = $this->actorWithPlan();

        $source1 = LeadSource::create([
            'workspace_id' => $workspace1->id,
            'name' => 'Source Workspace 1',
            'type' => 'webhook',
            'status' => 'active',
        ]);

        // User 2 cannot edit or delete User 1's source
        $response = $this->actingAs($user2)->put(route('client.sales.lead-sources.update', $source1->id), [
            'name' => 'Hacked Name',
            'status' => 'active',
        ]);
        $response->assertStatus(403);

        $deleteResponse = $this->actingAs($user2)->delete(route('client.sales.lead-sources.destroy', $source1->id));
        $deleteResponse->assertStatus(403);
    }
}

