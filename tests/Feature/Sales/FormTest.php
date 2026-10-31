<?php

namespace Tests\Feature\Sales;

use App\Events\FormSubmitted;
use App\Models\Plan;
use App\Models\User;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\PipelineManager;
use App\Modules\Sales\Models\Form;
use App\Modules\Sales\Models\FormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function actorWithPlan(mixed $formsFeature = 10, mixed $submissionCap = 5000): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create([
            'feature_flags' => [
                'forms' => $formsFeature,
                'form_submissions' => $submissionCap,
            ],
        ]);

        $this->attachPlanToClient($client, $plan);

        return [$user, $workspace, $client];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function publishedForm($workspace, array $overrides = []): Form
    {
        $form = Form::create(array_merge([
            'workspace_id' => $workspace->id,
            'name' => 'Inbound Web Contact',
            'status' => 'published',
        ], $overrides));

        $form->fields()->createMany([
            ['type' => 'text', 'label' => 'Name', 'field_key' => 'name', 'required' => true, 'maps_to' => 'name', 'sort_order' => 1],
            ['type' => 'email', 'label' => 'Email', 'field_key' => 'email', 'required' => true, 'maps_to' => 'email', 'sort_order' => 2],
            ['type' => 'phone', 'label' => 'Phone', 'field_key' => 'phone', 'required' => false, 'maps_to' => 'phone', 'sort_order' => 3],
        ]);

        return $form;
    }

    #[Test]
    public function forms_feature_gate_blocks_when_disabled(): void
    {
        [$user] = $this->actorWithPlan(formsFeature: false);

        $response = $this->actingAs($user)->get(route('client.sales.forms.index'));
        $response->assertRedirect(route('client.pricing'));
        $response->assertSessionHas('upgrade_required', true);
    }

    #[Test]
    public function can_create_form_and_plan_limit_is_enforced(): void
    {
        [$user, $workspace] = $this->actorWithPlan(formsFeature: 2);

        // Form 1
        $response1 = $this->actingAs($user)->post(route('client.sales.forms.store'), [
            'name' => 'Contact Sales Form 1',
        ]);
        $response1->assertRedirect();
        $this->assertDatabaseHas('forms', ['workspace_id' => $workspace->id, 'name' => 'Contact Sales Form 1']);

        // Form 2
        $response2 = $this->actingAs($user)->post(route('client.sales.forms.store'), [
            'name' => 'Contact Sales Form 2',
        ]);
        $response2->assertRedirect();
        $this->assertDatabaseHas('forms', ['workspace_id' => $workspace->id, 'name' => 'Contact Sales Form 2']);

        // Form 3 (Limit reached)
        $response3 = $this->actingAs($user)->post(route('client.sales.forms.store'), [
            'name' => 'Contact Sales Form 3',
        ]);
        $response3->assertSessionHasErrors('forms');
        $this->assertDatabaseMissing('forms', ['name' => 'Contact Sales Form 3']);
    }

    #[Test]
    public function can_update_form_and_sync_fields_in_builder(): void
    {
        [$user, $workspace] = $this->actorWithPlan(formsFeature: 10);

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $form = Form::create([
            'workspace_id' => $workspace->id,
            'name' => 'Demo Form',
            'slug' => 'demo-form',
            'status' => 'published',
            'pipeline_id' => $pipeline->id,
            'target_stage_id' => $stage->id,
        ]);

        $response = $this->actingAs($user)->put(route('client.sales.forms.builder.update', $form->id), [
            'name' => 'Updated Demo Form',
            'status' => 'published',
            'pipeline_id' => $pipeline->id,
            'target_stage_id' => $stage->id,
            'assignment_mode' => 'none',
            'assigned_user_id' => null,
            'thank_you_type' => 'message',
            'thank_you_value' => 'Thank you kindly!',
            'fields' => [
                [
                    'type' => 'text',
                    'label' => 'Full Name',
                    'field_key' => 'full_name',
                    'placeholder' => 'Enter your full name',
                    'required' => true,
                    'maps_to' => 'name',
                    'sort_order' => 1,
                ],
                [
                    'type' => 'email',
                    'label' => 'Email Address',
                    'field_key' => 'contact_email',
                    'placeholder' => 'you@domain.com',
                    'required' => true,
                    'maps_to' => 'email',
                    'sort_order' => 2,
                ],
                [
                    'type' => 'number',
                    'label' => 'Estimated Deal Value',
                    'field_key' => 'budget',
                    'placeholder' => '5000',
                    'required' => false,
                    'maps_to' => 'deal_value',
                    'sort_order' => 3,
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $form->refresh();
        $this->assertSame('Updated Demo Form', $form->name);
        $this->assertCount(3, $form->fields);
        $this->assertDatabaseHas('form_fields', [
            'form_id' => $form->id,
            'field_key' => 'contact_email',
            'maps_to' => 'email',
        ]);
    }

    #[Test]
    public function public_form_view_increments_views_count(): void
    {
        [$user, $workspace] = $this->actorWithPlan(formsFeature: 10);

        $form = Form::create([
            'workspace_id' => $workspace->id,
            'name' => 'Quote Request',
            'slug' => 'quote-request',
            'status' => 'published',
            'views_count' => 5,
        ]);

        $response = $this->get(route('forms.public.show', $form->slug));
        $response->assertStatus(200);

        $form->refresh();
        $this->assertSame(6, $form->views_count);
    }

    #[Test]
    public function public_form_page_exposes_spam_settings_to_the_widget(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        config([
            'services.turnstile.secret' => 'test-secret',
            'services.turnstile.site_key' => 'test-site-key',
        ]);

        $form = $this->publishedForm($workspace, ['spam_protection' => 'turnstile']);

        $response = $this->get(route('forms.public.show', $form->slug));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Forms/PublicForm')
                ->where('form.spam_protection', 'turnstile')
                ->where('form.spam_site_key', 'test-site-key'));

        // The widget's script and challenge frame must survive the CSP.
        $this->assertStringContainsString(
            'https://challenges.cloudflare.com',
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    #[Test]
    public function public_form_submission_creates_lead_and_records_submission(): void
    {
        [$user, $workspace] = $this->actorWithPlan(formsFeature: 10);

        Event::fake([FormSubmitted::class]);

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $form = Form::create([
            'workspace_id' => $workspace->id,
            'name' => 'Inbound Web Contact',
            'slug' => 'web-contact',
            'status' => 'published',
            'pipeline_id' => $pipeline->id,
            'target_stage_id' => $stage->id,
            'auto_tags' => ['Website Lead', 'Inbound'],
        ]);

        $form->fields()->createMany([
            [
                'type' => 'text',
                'label' => 'Name',
                'field_key' => 'name',
                'required' => true,
                'maps_to' => 'name',
                'sort_order' => 1,
            ],
            [
                'type' => 'email',
                'label' => 'Email',
                'field_key' => 'email',
                'required' => true,
                'maps_to' => 'email',
                'sort_order' => 2,
            ],
            [
                'type' => 'phone',
                'label' => 'Phone',
                'field_key' => 'phone',
                'required' => false,
                'maps_to' => 'phone',
                'sort_order' => 3,
            ],
            [
                'type' => 'number',
                'label' => 'Budget',
                'field_key' => 'budget',
                'required' => false,
                'maps_to' => 'deal_value',
                'sort_order' => 4,
            ],
        ]);

        // Submit form via public endpoint with UTM parameters
        $response = $this->postJson(route('forms.public.submit', $form->slug), [
            'name' => 'Alice Morgan',
            'email' => 'alice@wonderland.com',
            'phone' => '+1 555-4321',
            'budget' => 12500,
            'utm_source' => 'google_ads',
            'utm_campaign' => 'spring_promo',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // 1. Lead was created
        $this->assertDatabaseHas('leads', [
            'workspace_id' => $workspace->id,
            'name' => 'Alice Morgan',
            'email' => 'alice@wonderland.com',
            'phone' => '+1 555-4321',
            'deal_value' => 12500,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        $lead = Lead::where('email', 'alice@wonderland.com')->first();
        $this->assertNotNull($lead);

        // 2. Auto tags attached
        $tagNames = $lead->tags->pluck('name')->all();
        $this->assertContains('Website Lead', $tagNames);
        $this->assertContains('Inbound', $tagNames);

        // 3. Form submission recorded
        $this->assertDatabaseHas('form_submissions', [
            'form_id' => $form->id,
            'lead_id' => $lead->id,
            'utm_source' => 'google_ads',
            'utm_campaign' => 'spring_promo',
        ]);

        // 4. Form submissions counter incremented
        $form->refresh();
        $this->assertSame(1, $form->submissions_count);

        // 5. Activity logged
        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id,
            'type' => 'note',
        ]);

        // 6. Automation trigger event dispatched
        Event::assertDispatched(FormSubmitted::class, function (FormSubmitted $event) use ($form, $lead) {
            return $event->formId === $form->id
                && $event->leadId === $lead->id
                && $event->formName === 'Inbound Web Contact';
        });
    }

    #[Test]
    public function resubmission_reuses_the_existing_lead_and_only_fills_missing_fields(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $form = $this->publishedForm($workspace);

        $this->postJson(route('forms.public.submit', $form->slug), [
            'name' => 'Alice Morgan',
            'email' => 'Alice@Example.com',
        ])->assertOk();

        $lead = Lead::where('workspace_id', $workspace->id)->sole();

        $this->postJson(route('forms.public.submit', $form->slug), [
            'name' => 'Alice Smith',
            'email' => 'alice@example.com',
            'phone' => '+1 555 0100',
        ])->assertOk();

        $this->assertSame(1, Lead::where('workspace_id', $workspace->id)->count());

        $lead->refresh();
        $this->assertSame('Alice Morgan', $lead->name);
        $this->assertSame('+1 555 0100', $lead->phone);

        $form->refresh();
        $this->assertSame(2, $form->submissions_count);
        $this->assertSame(2, $form->submissions()->count());
    }

    #[Test]
    public function monthly_submission_cap_blocks_further_submissions(): void
    {
        [$user, $workspace] = $this->actorWithPlan(submissionCap: 1);

        $form = $this->publishedForm($workspace);

        $this->postJson(route('forms.public.submit', $form->slug), [
            'name' => 'Alice Morgan',
            'email' => 'alice@example.com',
        ])->assertOk();

        $response = $this->postJson(route('forms.public.submit', $form->slug), [
            'name' => 'Bob Stone',
            'email' => 'bob@example.com',
        ]);

        $response->assertStatus(429);
        $response->assertJson(['success' => false]);
        $response->assertJsonStructure(['message']);

        $this->assertSame(1, Lead::where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, FormSubmission::where('form_id', $form->id)->count());
    }

    #[Test]
    public function unlimited_submission_cap_does_not_block(): void
    {
        [$user, $workspace] = $this->actorWithPlan(submissionCap: null);

        $form = $this->publishedForm($workspace);

        $this->postJson(route('forms.public.submit', $form->slug), [
            'name' => 'Alice Morgan',
            'email' => 'alice@example.com',
        ])->assertOk();

        $this->postJson(route('forms.public.submit', $form->slug), [
            'name' => 'Bob Stone',
            'email' => 'bob@example.com',
        ])->assertOk();

        $this->assertSame(2, Lead::where('workspace_id', $workspace->id)->count());
    }

    #[Test]
    public function form_with_spam_protection_rejects_a_missing_token(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        config([
            'services.turnstile.secret' => 'test-secret',
            'services.turnstile.site_key' => 'test-site-key',
        ]);

        $form = $this->publishedForm($workspace, ['spam_protection' => 'turnstile']);

        $response = $this->postJson(route('forms.public.submit', $form->slug), [
            'name' => 'Alice Morgan',
            'email' => 'alice@example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);

        $this->assertSame(0, Lead::where('workspace_id', $workspace->id)->count());
    }

    #[Test]
    public function form_with_spam_protection_rejects_a_token_the_provider_disapproves(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        config(['services.turnstile.secret' => 'test-secret']);

        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => false], 200),
        ]);

        $form = $this->publishedForm($workspace, ['spam_protection' => 'turnstile']);

        $response = $this->postJson(route('forms.public.submit', $form->slug), [
            'name' => 'Alice Morgan',
            'email' => 'alice@example.com',
            'cf-turnstile-response' => 'bad-token',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Lead::where('workspace_id', $workspace->id)->count());
    }

    #[Test]
    public function form_with_spam_protection_fails_closed_when_the_secret_is_missing(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        config(['services.turnstile.secret' => null]);

        $form = $this->publishedForm($workspace, ['spam_protection' => 'turnstile']);

        $response = $this->postJson(route('forms.public.submit', $form->slug), [
            'name' => 'Alice Morgan',
            'email' => 'alice@example.com',
            'cf-turnstile-response' => 'some-token',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Lead::where('workspace_id', $workspace->id)->count());
    }

    #[Test]
    public function form_with_spam_protection_accepts_a_verified_token_and_keeps_it_out_of_the_payload(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        config([
            'services.turnstile.secret' => 'test-secret',
            'services.turnstile.site_key' => 'test-site-key',
        ]);

        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true], 200),
        ]);

        $form = $this->publishedForm($workspace, ['spam_protection' => 'turnstile']);

        $this->postJson(route('forms.public.submit', $form->slug), [
            'name' => 'Alice Morgan',
            'email' => 'alice@example.com',
            'cf-turnstile-response' => 'good-token',
        ])->assertOk();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
                && $request['secret'] === 'test-secret'
                && $request['response'] === 'good-token';
        });

        $submission = FormSubmission::where('form_id', $form->id)->sole();
        $this->assertArrayNotHasKey('cf-turnstile-response', $submission->payload);
        $this->assertSame(1, Lead::where('workspace_id', $workspace->id)->count());
    }

    #[Test]
    public function form_without_spam_protection_does_not_call_a_provider(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        Http::preventStrayRequests();

        $form = $this->publishedForm($workspace);

        $this->postJson(route('forms.public.submit', $form->slug), [
            'name' => 'Alice Morgan',
            'email' => 'alice@example.com',
        ])->assertOk();

        Http::assertNothingSent();
    }

    #[Test]
    public function builder_can_save_spam_protection(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $form = Form::create([
            'workspace_id' => $workspace->id,
            'name' => 'Spam Settings Form',
            'slug' => 'spam-settings-form',
            'status' => 'published',
            'pipeline_id' => $pipeline->id,
            'target_stage_id' => $stage->id,
        ]);

        $this->actingAs($user)->put(route('client.sales.forms.builder.update', $form->id), [
            'name' => 'Spam Settings Form',
            'status' => 'published',
            'pipeline_id' => $pipeline->id,
            'target_stage_id' => $stage->id,
            'assignment_mode' => 'none',
            'assigned_user_id' => null,
            'thank_you_type' => 'message',
            'thank_you_value' => 'Thanks!',
            'spam_protection' => 'recaptcha',
            'fields' => [
                ['type' => 'text', 'label' => 'Name', 'field_key' => 'name', 'required' => true, 'maps_to' => 'name'],
            ],
        ])->assertRedirect();

        $this->assertSame('recaptcha', $form->fresh()->spam_protection);
    }

    #[Test]
    public function form_embed_js_endpoint_serves_javascript(): void
    {
        [$user, $workspace] = $this->actorWithPlan(formsFeature: 10);

        $form = Form::create([
            'workspace_id' => $workspace->id,
            'name' => 'Embed Test',
            'slug' => 'embed-test',
            'status' => 'published',
        ]);

        $response = $this->get(route('forms.public.embed', $form->slug));
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/javascript');
        $this->assertStringContainsString('iframe', $response->getContent());
    }

    #[Test]
    public function workspace_isolation_is_enforced(): void
    {
        [$userA, $workspaceA] = $this->actorWithPlan(formsFeature: 10);
        [$userB, $workspaceB] = $this->actorWithPlan(formsFeature: 10);

        $formA = Form::create([
            'workspace_id' => $workspaceA->id,
            'name' => 'Workspace A Form',
            'slug' => 'ws-a-form',
            'status' => 'published',
        ]);

        // User B cannot access builder of Form A
        $response = $this->actingAs($userB)->get(route('client.sales.forms.builder', $formA->id));
        $response->assertStatus(403);

        // User B cannot update Form A
        $response = $this->actingAs($userB)->put(route('client.sales.forms.builder.update', $formA->id), [
            'name' => 'Hacked Name',
            'status' => 'published',
            'assignment_mode' => 'none',
            'assigned_user_id' => null,
            'thank_you_type' => 'message',
            'fields' => [
                ['type' => 'text', 'label' => 'Test', 'field_key' => 'test', 'required' => false],
            ],
        ]);
        $response->assertStatus(403);

        // User B cannot delete Form A
        $response = $this->actingAs($userB)->delete(route('client.sales.forms.destroy', $formA->id));
        $response->assertStatus(403);
    }
}
