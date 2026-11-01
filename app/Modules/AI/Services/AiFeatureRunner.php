<?php

namespace App\Modules\AI\Services;

use App\Models\Workspace;
use App\Modules\AI\Exceptions\AiFeatureNotIncludedException;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Modules\AI\Support\AiJson;
use App\Services\FeatureGate;

/**
 * The single door every AI feature goes through.
 *
 * It enforces the plan gate, meters the tokens and labels the run with the
 * feature that issued it, so "everything runs through the plan gate and the
 * usage counter from day one" is a property of the code path rather than a
 * promise each new feature has to remember.
 */
class AiFeatureRunner
{
    /**
     * Workspace id => (gate => enabled). A board-wide or batch operation
     * resolves the workspace's plan once instead of per item.
     *
     * @var array<int, array<string, bool>>
     */
    private array $gateCache = [];

    public function __construct(private readonly LlmGateway $gateway) {}

    /**
     * Whether the workspace's plan includes a gated AI feature.
     */
    public function includes(int $workspaceId, string $feature): bool
    {
        return $this->gateCache[$workspaceId][$feature] ??= FeatureGate::forWorkspace(
            Workspace::find($workspaceId)
        )->enabled($feature);
    }

    /**
     * A metered chat completion tagged with the calling feature.
     *
     * @param  array<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $opts
     * @param  string|null  $requiredFeature  Plan gate to enforce, when the feature is sold separately
     *
     * @throws AiFeatureNotIncludedException when $requiredFeature is not on the plan
     */
    public function chat(
        int $workspaceId,
        string $feature,
        array $messages,
        array $opts = [],
        ?string $requiredFeature = null,
    ): LlmResponse {
        if ($requiredFeature !== null && ! $this->includes($workspaceId, $requiredFeature)) {
            throw new AiFeatureNotIncludedException($requiredFeature);
        }

        return $this->gateway->chat($workspaceId, $messages, $opts, null, null, $feature);
    }

    /**
     * A chat completion whose reply is a JSON object, or null when the model did
     * not return one. Callers decide whether a missing object is an error.
     *
     * @param  array<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $opts
     * @return array<string, mixed>|null
     */
    public function json(
        int $workspaceId,
        string $feature,
        array $messages,
        array $opts = [],
        ?string $requiredFeature = null,
    ): ?array {
        $response = $this->chat($workspaceId, $feature, $messages, $opts, $requiredFeature);

        return AiJson::decode($response->content);
    }
}
