<?php

namespace Tests\Feature\Sales;

use App\Models\Plan;
use App\Models\User;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\PipelineManager;
use App\Modules\Sales\Models\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function actorWithPlan(mixed $formsFeature = 10): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create([
            'feature_flags' => [
                'forms' => $formsFeature,
                'form_submissions' => 5000,
            ],
        ]);

        $this->attachPlanToClient($client, $plan);

        return [$user, $workspace, $client];
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
    public function public_form_submission_creates_lead_and_records_submission(): void
    {
        [$user, $workspace] = $this->actorWithPlan(formsFeature: 10);

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
