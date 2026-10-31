<?php

namespace Tests\Feature\CustomFields;

use App\Models\Plan;
use App\Models\User;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\PipelineManager;
use App\Modules\Shared\Models\CustomField;
use App\Modules\Shared\Models\CustomFieldValue;
use App\Modules\Shared\Services\CustomFieldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomFieldTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, Plan::factory()->create([
            'feature_flags' => ['custom_fields' => 20],
        ]));

        return [$user, $workspace, $client];
    }

    #[Test]
    public function can_create_custom_fields_of_different_types(): void
    {
        [$user, $workspace] = $this->actor();

        // 1. Create text field
        $response = $this->actingAs($user)->post(route('client.custom-fields.store'), [
            'entity_type' => 'lead',
            'label' => 'Budget Bracket',
            'key' => 'budget_bracket',
            'type' => 'text',
            'required' => false,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('custom_fields', [
            'workspace_id' => $workspace->id,
            'entity_type' => 'lead',
            'key' => 'budget_bracket',
            'label' => 'Budget Bracket',
            'type' => 'text',
        ]);

        // 2. Create select field with options
        $response = $this->actingAs($user)->post(route('client.custom-fields.store'), [
            'entity_type' => 'lead',
            'label' => 'Preferred Contact Method',
            'key' => 'preferred_channel',
            'type' => 'select',
            'options' => ['WhatsApp', 'Email', 'Phone'],
            'required' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('custom_fields', [
            'workspace_id' => $workspace->id,
            'key' => 'preferred_channel',
            'type' => 'select',
            'required' => 1,
        ]);
    }

    #[Test]
    public function custom_field_can_be_updated(): void
    {
        [$user, $workspace] = $this->actor();

        $field = CustomField::create([
            'workspace_id' => $workspace->id,
            'entity_type' => 'lead',
            'key' => 'industry_sector',
            'label' => 'Old Label',
            'type' => 'select',
            'options' => ['Tech', 'Retail'],
            'required' => false,
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($user)->put(route('client.custom-fields.update', $field->id), [
            'label' => 'Industry Sector Updated',
            'options' => ['Tech', 'Retail', 'Healthcare'],
            'required' => true,
            'sort_order' => 5,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $field->refresh();
        $this->assertSame('Industry Sector Updated', $field->label);
        $this->assertTrue((bool) $field->required);
        $this->assertSame(5, $field->sort_order);
        $this->assertContains('Healthcare', $field->options);
    }

    #[Test]
    public function custom_field_and_its_values_can_be_deleted(): void
    {
        [$user, $workspace] = $this->actor();

        $field = CustomField::create([
            'workspace_id' => $workspace->id,
            'entity_type' => 'lead',
            'key' => 'license_number',
            'label' => 'License Number',
            'type' => 'text',
        ]);

        CustomFieldValue::create([
            'workspace_id' => $workspace->id,
            'field_id' => $field->id,
            'entity_type' => 'lead',
            'entity_id' => 999,
            'value' => 'LIC-12345',
        ]);

        $response = $this->actingAs($user)->delete(route('client.custom-fields.destroy', $field->id));
        $response->assertRedirect();

        $this->assertDatabaseMissing('custom_fields', ['id' => $field->id]);
        $this->assertDatabaseMissing('custom_field_values', ['field_id' => $field->id]);
    }

    #[Test]
    public function plan_limit_on_custom_fields_is_enforced(): void
    {
        [$user, $workspace, $client] = $this->actor();

        // Create a plan with a limit of 2 custom fields
        $plan = Plan::factory()->create([
            'name' => 'Starter Plan',
            'feature_flags' => [
                'custom_fields' => 2,
            ],
        ]);
        $this->attachPlanToClient($client, $plan);

        // Create 2 fields (reaching the limit)
        CustomField::create([
            'workspace_id' => $workspace->id,
            'entity_type' => 'lead',
            'key' => 'field_1',
            'label' => 'Field 1',
            'type' => 'text',
        ]);
        CustomField::create([
            'workspace_id' => $workspace->id,
            'entity_type' => 'lead',
            'key' => 'field_2',
            'label' => 'Field 2',
            'type' => 'number',
        ]);

        // 3rd field creation should fail validation
        $response = $this->actingAs($user)->post(route('client.custom-fields.store'), [
            'entity_type' => 'lead',
            'label' => 'Field 3',
            'key' => 'field_3',
            'type' => 'text',
        ]);

        $response->assertSessionHasErrors('custom_fields');
        $this->assertDatabaseMissing('custom_fields', ['key' => 'field_3']);
    }

    #[Test]
    public function can_sync_and_retrieve_custom_field_values_for_a_lead(): void
    {
        [$user, $workspace] = $this->actor();

        $textField = CustomField::create([
            'workspace_id' => $workspace->id,
            'entity_type' => 'lead',
            'key' => 'project_code',
            'label' => 'Project Code',
            'type' => 'text',
        ]);

        $numberField = CustomField::create([
            'workspace_id' => $workspace->id,
            'entity_type' => 'lead',
            'key' => 'employees_count',
            'label' => 'Employees Count',
            'type' => 'number',
        ]);

        $checkboxField = CustomField::create([
            'workspace_id' => $workspace->id,
            'entity_type' => 'lead',
            'key' => 'is_enterprise',
            'label' => 'Is Enterprise',
            'type' => 'checkbox',
        ]);

        $multiField = CustomField::create([
            'workspace_id' => $workspace->id,
            'entity_type' => 'lead',
            'key' => 'interested_services',
            'label' => 'Interested Services',
            'type' => 'multiselect',
            'options' => ['Cloud', 'Consulting', 'Security'],
        ]);

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        // Sync values via endpoint
        $response = $this->actingAs($user)->put(route('client.leads.custom-fields.update', $lead->id), [
            'values' => [
                'project_code' => 'PRJ-990',
                'employees_count' => 45,
                'is_enterprise' => true,
                'interested_services' => ['Cloud', 'Security'],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $service = app(CustomFieldService::class);
        $values = $service->valuesFor($workspace->id, 'lead', $lead->id);

        $this->assertSame('PRJ-990', $values['project_code']);
        $this->assertEquals(45, $values['employees_count']);
        $this->assertTrue($values['is_enterprise']);
        $this->assertEquals(['Cloud', 'Security'], $values['interested_services']);

        // Check formatted payload with definitions
        $withValues = $service->fieldsWithValues($workspace->id, 'lead', $lead->id);
        $this->assertCount(4, $withValues);

        $byKey = collect($withValues)->keyBy('key');
        $this->assertSame('PRJ-990', $byKey['project_code']['value']);
        $this->assertEquals(45, $byKey['employees_count']['value']);
        $this->assertTrue($byKey['is_enterprise']['value']);
        $this->assertEquals(['Cloud', 'Security'], $byKey['interested_services']['value']);
    }

    #[Test]
    public function workspace_isolation_is_strictly_enforced(): void
    {
        [$userA, $workspaceA] = $this->actor();
        [$userB, $workspaceB] = $this->actor();

        $fieldA = CustomField::create([
            'workspace_id' => $workspaceA->id,
            'entity_type' => 'lead',
            'key' => 'confidential_key',
            'label' => 'Confidential Field',
            'type' => 'text',
        ]);

        // User B cannot update User A's custom field
        $response = $this->actingAs($userB)->put(route('client.custom-fields.update', $fieldA->id), [
            'label' => 'Hacked Field',
        ]);
        $response->assertStatus(403);

        // User B cannot delete User A's custom field
        $response = $this->actingAs($userB)->delete(route('client.custom-fields.destroy', $fieldA->id));
        $response->assertStatus(403);

        $this->assertDatabaseHas('custom_fields', [
            'id' => $fieldA->id,
            'label' => 'Confidential Field',
        ]);
    }

    #[Test]
    public function custom_field_routes_are_feature_gated(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->get(route('client.custom-fields.index'))
            ->assertRedirect(route('client.pricing'))
            ->assertSessionHas('upgrade_required', true);
    }
}
