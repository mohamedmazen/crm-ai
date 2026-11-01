<?php

namespace Tests\Feature\AI;

use App\Models\Plan;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Models\AiRun;
use App\Modules\Broadcasting\Models\UsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The campaign subject optimiser is the pre-existing AI feature; Phase 6 puts it
 * behind the same ledger and the same monthly token cap as everything else.
 */
class SubjectOptimizerTest extends TestCase
{
    use RefreshDatabase;

    private function configureProvider(int $workspaceId): void
    {
        AiProviderConfig::create([
            'workspace_id' => $workspaceId,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'enabled' => true,
        ]);
    }

    #[Test]
    public function it_returns_three_subject_lines_and_meters_the_call(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $this->configureProvider($workspace->id);

        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => "1. Your account is ready\n2. Ready to start?\n3. Start now"]]],
                'usage' => ['prompt_tokens' => 90, 'completion_tokens' => 40],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('client.campaigns.improve-subject'), [
                'subject' => 'Welcome to Acme',
                'body' => '<p>Hello</p>',
            ])
            ->assertOk();

        $this->assertSame(
            ['Your account is ready', 'Ready to start?', 'Start now'],
            $response->json('suggestions'),
        );

        $run = AiRun::firstOrFail();
        $this->assertSame($workspace->id, $run->workspace_id);
        $this->assertSame('email_copy', $run->feature);

        $this->assertSame(130, UsageMeter::current($workspace->id, 'ai_tokens'));
    }

    #[Test]
    public function a_workspace_without_a_provider_gets_a_readable_error(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->postJson(route('client.campaigns.improve-subject'), ['subject' => 'Welcome'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'No AI provider configured. Set one up in AI → Providers.');
    }

    #[Test]
    public function the_monthly_token_cap_stops_the_call_before_it_reaches_the_provider(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create([
            'slug' => 'capped',
            'limits' => ['ai_tokens_per_month' => 500],
        ]));

        UsageMeter::track($workspace->id, 'ai_tokens', 500);

        $this->configureProvider($workspace->id);
        Http::preventStrayRequests();

        $this->actingAs($user)
            ->postJson(route('client.campaigns.improve-subject'), ['subject' => 'Welcome'])
            ->assertStatus(402)
            ->assertJsonPath('key', 'ai_tokens_per_month');

        $this->assertSame(0, AiRun::count());
    }
}
