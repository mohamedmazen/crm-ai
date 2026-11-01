<?php

namespace Tests\Feature\AI;

use App\Models\Plan;
use App\Modules\AI\Models\AiRun;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Smart Tags: the model proposes names, the user accepts them.
 *
 * The AI call itself is faked; what is asserted is the product contract — which
 * plan may ask, that the lead is scoped to the tenant, that nothing the model
 * returns escapes sanitising, and that an accepted tag is persisted once.
 */
class SmartTagsTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, bool>  $features */
    private function planWith(array $features): Plan
    {
        return Plan::factory()->create([
            'slug' => 'ai-'.implode('-', array_keys(array_filter($features))),
            'feature_flags' => $features,
        ]);
    }

    private function leadIn(int $workspaceId, array $attrs = []): Lead
    {
        return Lead::factory()->create(array_merge([
            'workspace_id' => $workspaceId,
            'name' => 'Dhaka Textiles Ltd',
            'category' => 'textile manufacturer',
        ], $attrs));
    }

    #[Test]
    public function suggestions_are_returned_as_clean_tag_names(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['ai_sales' => true]));

        $this->fakeLlmGateway(json_encode([
            'tags' => ['textile  manufacturer', 'textile manufacturer', 'High intent.', 42, 'dhaka', ''],
        ]));

        $lead = $this->leadIn($workspace->id);

        $response = $this->actingAs($user)
            ->postJson(route('client.leads.ai.tags.suggest', $lead->id))
            ->assertOk();

        // Whitespace collapses, the case-insensitive twin is dropped, the
        // trailing period goes, non-strings are ignored, blanks are ignored.
        $this->assertSame(
            ['textile manufacturer', 'High intent', 'dhaka'],
            $response->json('tags'),
        );
    }

    #[Test]
    public function suggestions_the_lead_already_has_are_filtered_out(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['ai_sales' => true]));

        $lead = $this->leadIn($workspace->id);
        $existing = LeadTag::create(['workspace_id' => $workspace->id, 'name' => 'vip']);
        $lead->tags()->attach($existing->id);

        $this->fakeLlmGateway(json_encode(['tags' => ['VIP', 'cold outreach']]));

        $response = $this->actingAs($user)
            ->postJson(route('client.leads.ai.tags.suggest', $lead->id))
            ->assertOk();

        $this->assertSame(['cold outreach'], $response->json('tags'));
    }

    #[Test]
    public function a_plan_without_ai_sales_cannot_ask_for_suggestions(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['ai_sales' => false]));
        $this->fakeLlmGateway('{"tags":["should not run"]}');

        $lead = $this->leadIn($workspace->id);

        $this->actingAs($user)
            ->postJson(route('client.leads.ai.tags.suggest', $lead->id))
            ->assertStatus(402)
            ->assertJsonPath('error', 'Your plan does not include AI sales assistant.');

        $this->assertSame(0, AiRun::count());
    }

    #[Test]
    public function a_lead_from_another_workspace_is_not_reachable(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['ai_sales' => true]));

        ['workspace' => $otherWorkspace] = $this->createWorkspaceContext();
        $foreignLead = $this->leadIn($otherWorkspace->id);
        $this->fakeLlmGateway('{"tags":["leak"]}');

        $this->actingAs($user)
            ->postJson(route('client.leads.ai.tags.suggest', $foreignLead->id))
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson(route('client.leads.ai.tags.apply', $foreignLead->id), ['tag_names' => ['leak']])
            ->assertForbidden();

        $this->assertSame(0, $foreignLead->tags()->count());
    }

    #[Test]
    public function a_model_reply_without_json_yields_no_suggestions(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['ai_sales' => true]));
        $this->fakeLlmGateway('I cannot help with that.');

        $lead = $this->leadIn($workspace->id);

        $this->actingAs($user)
            ->postJson(route('client.leads.ai.tags.suggest', $lead->id))
            ->assertOk()
            ->assertJsonPath('tags', []);
    }

    #[Test]
    public function asking_for_suggestions_without_a_provider_returns_a_readable_error(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['ai_sales' => true]));

        $lead = $this->leadIn($workspace->id);

        $this->actingAs($user)
            ->postJson(route('client.leads.ai.tags.suggest', $lead->id))
            ->assertStatus(422)
            ->assertJsonPath('error', 'No AI provider configured. Set one up in AI → Providers.');
    }

    #[Test]
    public function accepting_a_suggestion_attaches_it_once_and_returns_the_full_list(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['ai_sales' => true]));

        $lead = $this->leadIn($workspace->id);
        LeadTag::create(['workspace_id' => $workspace->id, 'name' => 'vip']);

        $response = $this->actingAs($user)
            ->postJson(route('client.leads.ai.tags.apply', $lead->id), ['tag_names' => ['vip', 'Textile manufacturer']])
            ->assertOk();

        $this->assertSame(['Textile manufacturer', 'vip'], collect($response->json('tags'))->pluck('name')->sort()->values()->all());

        // Sending the same names again must not duplicate the pivot rows.
        $this->actingAs($user)
            ->postJson(route('client.leads.ai.tags.apply', $lead->id), ['tag_names' => ['textile manufacturer']])
            ->assertOk();

        $this->assertSame(2, $lead->tags()->count());
        $this->assertSame(2, LeadTag::where('workspace_id', $workspace->id)->count());
    }

    #[Test]
    public function accepting_tags_validates_the_payload(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['ai_sales' => true]));

        $lead = $this->leadIn($workspace->id);

        $this->actingAs($user)
            ->postJson(route('client.leads.ai.tags.apply', $lead->id), ['tag_names' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tag_names');
    }

    #[Test]
    public function a_workspace_at_its_token_cap_cannot_spend_more(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $plan = Plan::factory()->create([
            'slug' => 'capped',
            'feature_flags' => ['ai_sales' => true],
            'limits' => ['ai_tokens_per_month' => 100],
        ]);
        $this->attachPlanToClient($client, $plan);

        UsageMeter::track($workspace->id, 'ai_tokens', 100);
        $this->fakeLlmGateway('{"tags":["should not run"]}');

        $lead = $this->leadIn($workspace->id);

        $this->actingAs($user)
            ->postJson(route('client.leads.ai.tags.suggest', $lead->id))
            ->assertStatus(402)
            ->assertJsonPath('key', 'ai_tokens_per_month');
    }
}
