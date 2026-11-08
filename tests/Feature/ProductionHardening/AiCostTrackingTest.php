<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Models\AiRun;
use App\Modules\AI\Services\LlmGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `ai_runs.cost_cents` is derived from the configured token price map instead
 * of being hard-coded to zero.
 */
class AiCostTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function configureOpenAi(int $workspaceId, string $model = 'gpt-4o-mini'): void
    {
        AiProviderConfig::create([
            'workspace_id' => $workspaceId,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => $model,
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);
    }

    #[Test]
    public function cost_is_computed_from_the_model_price_map(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $this->configureOpenAi($workspace->id);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Hi!']]],
                'usage' => ['prompt_tokens' => 100_000, 'completion_tokens' => 50_000],
                'model' => 'gpt-4o-mini',
            ], 200),
        ]);

        app(LlmGateway::class)->chat($workspace->id, [['role' => 'user', 'content' => 'hi']]);

        // 100k * 15c/1M + 50k * 60c/1M = 1.5 + 3.0 = 4.5 -> 5 cents.
        $run = AiRun::where('workspace_id', $workspace->id)->latest('id')->firstOrFail();

        $this->assertSame(5, $run->cost_cents);
    }

    #[Test]
    public function an_unpriced_model_records_zero_cost(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $this->configureOpenAi($workspace->id);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Hi!']]],
                'usage' => ['prompt_tokens' => 100_000, 'completion_tokens' => 50_000],
                'model' => 'some-new-model',
            ], 200),
        ]);

        app(LlmGateway::class)->chat($workspace->id, [['role' => 'user', 'content' => 'hi']]);

        $run = AiRun::where('workspace_id', $workspace->id)->latest('id')->firstOrFail();

        $this->assertSame(0, $run->cost_cents);
    }
}
