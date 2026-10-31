<?php

namespace Tests\Feature\Leads;

use App\Models\Plan;
use App\Models\User;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadTag;
use App\Modules\Leads\Services\PipelineManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeadBulkActionsAndImportExportTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, Plan::factory()->create([
            'feature_flags' => ['lead_import' => true],
        ]));

        return [$user, $workspace, $client];
    }

    #[Test]
    public function bulk_assign_assigns_multiple_leads_to_user(): void
    {
        [$user, $workspace, $client] = $this->actor();

        $member = User::factory()->create([
            'client_id' => $client->id,
            'workspace_id' => $workspace->id,
            'status' => User::STATUS_ACTIVE,
        ]);

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $leads = Lead::factory()->count(3)->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'assigned_user_id' => null,
        ]);

        $response = $this->actingAs($user)
            ->post(route('client.leads.bulk.assign'), [
                'ids' => $leads->pluck('id')->all(),
                'user_id' => $member->id,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        foreach ($leads as $lead) {
            $this->assertEquals($member->id, $lead->refresh()->assigned_user_id);
        }
    }

    #[Test]
    public function bulk_move_stage_moves_multiple_leads_to_new_stage(): void
    {
        [$user, $workspace] = $this->actor();

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stages = app(PipelineManager::class)->stagesFor($workspace->id, $pipeline->id);

        $stage1 = $stages[0];
        $stage2 = $stages[1];

        $leads = Lead::factory()->count(2)->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage1->id,
        ]);

        $response = $this->actingAs($user)
            ->post(route('client.leads.bulk.stage'), [
                'ids' => $leads->pluck('id')->all(),
                'stage_id' => $stage2->id,
            ]);

        $response->assertRedirect();

        foreach ($leads as $lead) {
            $this->assertEquals($stage2->id, $lead->refresh()->stage_id);
        }
    }

    #[Test]
    public function bulk_tags_adds_and_removes_tags_for_multiple_leads(): void
    {
        [$user, $workspace] = $this->actor();

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $leads = Lead::factory()->count(2)->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        // Add tags
        $response = $this->actingAs($user)
            ->post(route('client.leads.bulk.tags'), [
                'ids' => $leads->pluck('id')->all(),
                'action' => 'add',
                'tag_names' => ['VIP', 'Enterprise'],
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('lead_tags', ['workspace_id' => $workspace->id, 'name' => 'VIP']);
        $this->assertDatabaseHas('lead_tags', ['workspace_id' => $workspace->id, 'name' => 'Enterprise']);

        foreach ($leads as $lead) {
            $this->assertCount(2, $lead->refresh()->tags);
        }

        // Remove tag
        $this->actingAs($user)
            ->post(route('client.leads.bulk.tags'), [
                'ids' => $leads->pluck('id')->all(),
                'action' => 'remove',
                'tag_names' => ['VIP'],
            ]);

        foreach ($leads as $lead) {
            $tagNames = $lead->refresh()->tags->pluck('name')->all();
            $this->assertNotContains('VIP', $tagNames);
            $this->assertContains('Enterprise', $tagNames);
        }
    }

    #[Test]
    public function bulk_delete_deletes_selected_leads(): void
    {
        [$user, $workspace] = $this->actor();

        $leads = Lead::factory()->count(2)->create([
            'workspace_id' => $workspace->id,
        ]);

        $response = $this->actingAs($user)
            ->post(route('client.leads.bulk.destroy'), [
                'ids' => $leads->pluck('id')->all(),
            ]);

        $response->assertRedirect();

        foreach ($leads as $lead) {
            $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
        }
    }

    #[Test]
    public function export_downloads_filtered_csv_of_leads(): void
    {
        [$user, $workspace] = $this->actor();

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'name' => 'Acme Corp Mega Deal',
            'phone' => '+15551234567',
            'deal_value' => 7500.00,
        ]);

        $tag = LeadTag::create(['workspace_id' => $workspace->id, 'name' => 'Q4-Priority']);
        $lead->tags()->attach($tag->id);

        $response = $this->actingAs($user)
            ->get(route('client.leads.export'));

        $response->assertOk();
        $this->assertTrue(str_contains($response->headers->get('content-type'), 'text/csv'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('Acme Corp Mega Deal', $content);
        $this->assertStringContainsString('+15551234567', $content);
        $this->assertStringContainsString('7500', $content);
        $this->assertStringContainsString('Q4-Priority', $content);
    }

    #[Test]
    public function import_creates_new_leads_and_attaches_tags_from_csv(): void
    {
        [$user, $workspace] = $this->actor();

        $csvContent = "Name,Phone,Email,Category,Deal Value\n"
            ."Stark Industries,+1800111222,tony@stark.com,Defense,50000\n"
            ."Wayne Enterprises,+1800333444,bruce@wayne.com,Technology,75000\n";

        $file = UploadedFile::fake()->createWithContent('leads.csv', $csvContent);

        $response = $this->actingAs($user)
            ->post(route('client.leads.import'), [
                'file' => $file,
                'tag_names' => ['Inbound 2026'],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('leads', [
            'workspace_id' => $workspace->id,
            'name' => 'Stark Industries',
            'phone' => '+1800111222',
            'deal_value' => 50000,
        ]);

        $this->assertDatabaseHas('leads', [
            'workspace_id' => $workspace->id,
            'name' => 'Wayne Enterprises',
            'phone' => '+1800333444',
            'deal_value' => 75000,
        ]);

        $stark = Lead::where('workspace_id', $workspace->id)->where('name', 'Stark Industries')->first();
        $this->assertContains('Inbound 2026', $stark->tags->pluck('name')->all());
    }

    #[Test]
    public function import_deduplicates_by_phone_and_updates_existing_lead(): void
    {
        [$user, $workspace] = $this->actor();

        $existing = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'phone' => '+1800999888',
            'name' => 'Original Name',
            'deal_value' => 1000,
        ]);

        $csvContent = "Name,Phone,Email,Deal Value\n"
            ."Updated Mega Corp,+1800999888,updated@megacorp.com,9000\n";

        $file = UploadedFile::fake()->createWithContent('leads.csv', $csvContent);

        $response = $this->actingAs($user)
            ->post(route('client.leads.import'), [
                'file' => $file,
            ]);

        $response->assertRedirect();

        $existing->refresh();
        $this->assertEquals('Updated Mega Corp', $existing->name);
        $this->assertEquals(9000, (float) $existing->deal_value);
        $this->assertEquals('updated@megacorp.com', $existing->email);

        $this->assertEquals(1, Lead::where('workspace_id', $workspace->id)->where('phone', '+1800999888')->count());
    }

    #[Test]
    public function import_and_export_routes_are_feature_gated(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->get(route('client.leads.export'))
            ->assertRedirect(route('client.pricing'))
            ->assertSessionHas('upgrade_required', true);
    }
}
