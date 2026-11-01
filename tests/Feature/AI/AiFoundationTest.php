<?php

namespace Tests\Feature\AI;

use App\Models\Plan;
use App\Modules\AI\Exceptions\AiFeatureNotIncludedException;
use App\Modules\AI\Exceptions\AiNotConfiguredException;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Models\AiRun;
use App\Modules\AI\Services\AiFeatureRunner;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\AI\Support\AiJson;
use App\Modules\Broadcasting\Models\UsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiFoundationTest extends TestCase
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
    public function a_chat_run_is_ledgered_against_its_workspace_and_feature(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $this->configureProvider($workspace->id);

        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => 'Hello']]],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30],
            ]),
        ]);

        $response = app(LlmGateway::class)->chat(
            $workspace->id,
            [['role' => 'user', 'content' => 'Hi']],
            [],
            null,
            null,
            'lead_coach',
        );

        $this->assertSame('Hello', $response->content);

        $run = AiRun::firstOrFail();
        $this->assertSame($workspace->id, $run->workspace_id);
        $this->assertSame('lead_coach', $run->feature);
        $this->assertSame(150, $run->totalTokens());

        $this->assertSame(150, UsageMeter::current($workspace->id, 'ai_tokens'));
    }

    #[Test]
    public function an_unconfigured_workspace_raises_a_domain_exception(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $this->expectException(AiNotConfiguredException::class);

        app(LlmGateway::class)->chat($workspace->id, [['role' => 'user', 'content' => 'Hi']]);
    }

    #[Test]
    public function a_gated_feature_is_refused_when_the_plan_does_not_include_it(): void
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, Plan::factory()->create([
            'slug' => 'starter-test',
            'feature_flags' => ['ai_sales' => false],
        ]));

        $this->expectException(AiFeatureNotIncludedException::class);

        app(AiFeatureRunner::class)->chat(
            $workspace->id,
            'lead_coach',
            [['role' => 'user', 'content' => 'Hi']],
            [],
            'ai_sales',
        );
    }

    #[Test]
    public function a_gated_feature_runs_when_the_plan_includes_it(): void
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->fakeLlmGateway('ok');

        $this->attachPlanToClient($client, Plan::factory()->create([
            'slug' => 'pro-test',
            'feature_flags' => ['ai_sales' => true],
        ]));

        $response = app(AiFeatureRunner::class)->chat(
            $workspace->id,
            'lead_coach',
            [['role' => 'user', 'content' => 'Hi']],
            [],
            'ai_sales',
        );

        $this->assertSame('ok', $response->content);
    }

    #[Test]
    public function the_json_helper_extracts_an_object_from_a_fenced_reply(): void
    {
        $decoded = AiJson::decode("Sure! Here you go:\n```json\n{\"step\":\"Call back\",\"nested\":{\"a\":1}}\n```\nLet me know!");

        $this->assertSame('Call back', $decoded['step']);
        $this->assertSame(1, $decoded['nested']['a']);
    }

    #[Test]
    public function the_json_helper_returns_null_for_a_reply_without_json(): void
    {
        $this->assertNull(AiJson::decode('I cannot help with that.'));
        $this->assertNull(AiJson::decode(null));
    }
}
