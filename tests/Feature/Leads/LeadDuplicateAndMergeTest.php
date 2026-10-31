<?php

namespace Tests\Feature\Leads;

use App\Models\Plan;
use App\Models\User;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Leads\Models\LeadDuplicateGroup;
use App\Modules\Leads\Models\LeadTag;
use App\Modules\Leads\Models\LeadTask;
use App\Modules\Leads\Services\PipelineManager;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\CustomField;
use App\Modules\Shared\Models\CustomFieldValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeadDuplicateAndMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function actor(): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, Plan::factory()->create([
            'feature_flags' => ['duplicates' => true],
        ]));

        return [$user, $workspace, $client];
    }

    #[Test]
    public function can_detect_duplicate_leads_by_phone_and_email(): void
    {
        [$user, $workspace] = $this->actor();

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        // 2 leads with same phone
        Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'John Doe A',
            'phone' => '+15551234567',
            'email' => 'john.a@example.com',
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'John Doe B',
            'phone' => '+15551234567',
            'email' => 'john.b@example.com',
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        // 2 leads with same email (different case)
        Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Sarah Connor 1',
            'email' => 'sarah@skynet.com',
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Sarah Connor 2',
            'email' => 'SARAH@SKYNET.COM',
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        $response = $this->actingAs($user)->post(route('client.leads.duplicates.scan'));
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseCount('lead_duplicate_groups', 2);
        $this->assertDatabaseHas('lead_duplicate_groups', [
            'workspace_id' => $workspace->id,
            'match_type' => 'phone',
            'match_value' => '+15551234567',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('lead_duplicate_groups', [
            'workspace_id' => $workspace->id,
            'match_type' => 'email',
            'match_value' => 'sarah@skynet.com',
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function can_dismiss_duplicate_group(): void
    {
        [$user, $workspace] = $this->actor();

        $group = LeadDuplicateGroup::create([
            'workspace_id' => $workspace->id,
            'match_type' => 'phone',
            'match_value' => '+15550009999',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->post(route('client.leads.duplicates.dismiss', $group->id));
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $group->refresh();
        $this->assertSame(LeadDuplicateGroup::STATUS_DISMISSED, $group->status);
    }

    #[Test]
    public function can_merge_secondary_lead_into_primary_lead_with_all_relations_preserved(): void
    {
        [$user, $workspace] = $this->actor();

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $tagA = LeadTag::create(['workspace_id' => $workspace->id, 'name' => 'Enterprise', 'color' => '#10b981']);
        $tagB = LeadTag::create(['workspace_id' => $workspace->id, 'name' => 'High Intent', 'color' => '#3b82f6']);

        $customField = CustomField::create([
            'workspace_id' => $workspace->id,
            'entity_type' => 'lead',
            'key' => 'referral_source',
            'label' => 'Referral Source',
            'type' => 'text',
        ]);

        // Primary lead
        $primary = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Master Lead Record',
            'email' => 'master@acme.com',
            'phone' => null, // empty, will be filled from secondary
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'deal_value' => 5000,
        ]);
        $primary->tags()->attach($tagA->id);

        // Secondary lead
        $secondary = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Duplicate Secondary Record',
            'email' => 'master@acme.com',
            'phone' => '+15558887777',
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'deal_value' => 7000,
        ]);
        $secondary->tags()->attach($tagB->id);

        // A contact already pushed from the secondary lead. Merging must not
        // leave it pointing at a lead row that no longer exists.
        $contact = Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'lead_id' => $secondary->id,
        ]);

        // Secondary has an activity, task, and custom field value
        $activity = LeadActivity::create([
            'workspace_id' => $workspace->id,
            'lead_id' => $secondary->id,
            'user_id' => $user->id,
            'type' => 'call',
            'body' => 'Spoke with client regarding contract terms',
            'occurred_at' => now(),
        ]);

        $task = LeadTask::create([
            'workspace_id' => $workspace->id,
            'lead_id' => $secondary->id,
            'title' => 'Send contract PDF',
            'type' => 'email',
            'priority' => 'high',
            'due_at' => now()->addDays(1),
        ]);

        CustomFieldValue::create([
            'workspace_id' => $workspace->id,
            'field_id' => $customField->id,
            'entity_type' => 'lead',
            'entity_id' => $secondary->id,
            'value' => 'Partner Referral',
        ]);

        $group = LeadDuplicateGroup::create([
            'workspace_id' => $workspace->id,
            'match_type' => 'email',
            'match_value' => 'master@acme.com',
            'status' => 'pending',
        ]);
        $group->leads()->sync([$primary->id, $secondary->id]);

        // Execute merge via controller endpoint
        $response = $this->actingAs($user)->post(route('client.leads.duplicates.merge', $group->id), [
            'primary_lead_id' => $primary->id,
            'secondary_lead_ids' => [$secondary->id],
            'overrides' => [
                'deal_value' => 7000,
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // 1. Secondary lead deleted
        $this->assertDatabaseMissing('leads', ['id' => $secondary->id]);

        // 2. Primary lead updated with phone filled in and deal_value overridden
        $primary->refresh();
        $this->assertSame('+15558887777', $primary->phone);
        $this->assertEquals(7000, $primary->deal_value);

        // 3. Activity and Task moved to primary lead
        $this->assertDatabaseHas('lead_activities', [
            'id' => $activity->id,
            'lead_id' => $primary->id,
        ]);
        $this->assertDatabaseHas('lead_tasks', [
            'id' => $task->id,
            'lead_id' => $primary->id,
        ]);

        // 4. Tags merged (primary now has tagA and tagB)
        $primaryTagIds = $primary->tags->pluck('id')->all();
        $this->assertContains($tagA->id, $primaryTagIds);
        $this->assertContains($tagB->id, $primaryTagIds);

        // 5. Custom field value moved to primary lead
        $this->assertDatabaseHas('custom_field_values', [
            'field_id' => $customField->id,
            'entity_type' => 'lead',
            'entity_id' => $primary->id,
            'value' => 'Partner Referral',
        ]);

        // 6. System merge activity logged
        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $primary->id,
            'type' => 'note',
        ]);

        // 7. Duplicate group marked as merged
        $group->refresh();
        $this->assertSame(LeadDuplicateGroup::STATUS_MERGED, $group->status);

        // 8. Contact repointed to the surviving primary lead
        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'lead_id' => $primary->id,
        ]);
    }

    #[Test]
    public function workspace_isolation_is_enforced_for_duplicates(): void
    {
        [$userA, $workspaceA] = $this->actor();
        [$userB, $workspaceB] = $this->actor();

        $groupA = LeadDuplicateGroup::create([
            'workspace_id' => $workspaceA->id,
            'match_type' => 'phone',
            'match_value' => '+15551112222',
            'status' => 'pending',
        ]);

        // User B cannot dismiss Group A
        $response = $this->actingAs($userB)->post(route('client.leads.duplicates.dismiss', $groupA->id));
        $response->assertStatus(403);

        // User B cannot merge Group A
        $response = $this->actingAs($userB)->post(route('client.leads.duplicates.merge', $groupA->id), [
            'primary_lead_id' => 1,
            'secondary_lead_ids' => [2],
        ]);
        $response->assertStatus(403);
    }

    #[Test]
    public function duplicate_routes_are_feature_gated(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->get(route('client.leads.duplicates.index'))
            ->assertRedirect(route('client.pricing'))
            ->assertSessionHas('upgrade_required', true);
    }
}
