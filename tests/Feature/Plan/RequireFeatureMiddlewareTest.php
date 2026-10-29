<?php

namespace Tests\Feature\Plan;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RequireFeatureMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'feature:tasks'])
            ->get('/_test/feature-gate', fn () => 'feature allowed');
    }

    public function test_request_passes_when_the_plan_includes_the_feature(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, Plan::factory()->create([
            'feature_flags' => ['tasks' => true],
        ]));

        $this->actingAs($user)
            ->get('/_test/feature-gate')
            ->assertOk()
            ->assertSee('feature allowed');
    }

    public function test_browser_request_is_redirected_to_pricing_with_upgrade_flash(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, Plan::factory()->create([
            'feature_flags' => ['tasks' => false],
        ]));

        $response = $this->actingAs($user)->get('/_test/feature-gate');

        $response->assertRedirect(route('client.pricing'));
        $response->assertSessionHas('upgrade_required', true);
        $response->assertSessionHas('upgrade_reason', fn ($message) => str_contains($message, 'Tasks & reminders'));
    }

    public function test_json_request_receives_http_402(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, Plan::factory()->create([
            'feature_flags' => ['tasks' => false],
        ]));

        $this->actingAs($user)
            ->getJson('/_test/feature-gate')
            ->assertStatus(402)
            ->assertJson([
                'upgrade_required' => true,
                'feature' => 'tasks',
            ])
            ->assertJsonPath('error', fn ($message) => str_contains($message, 'Tasks & reminders'));
    }

    public function test_user_without_a_plan_is_blocked(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->get('/_test/feature-gate')
            ->assertRedirect(route('client.pricing'))
            ->assertSessionHas('upgrade_required', true);
    }

    public function test_features_missing_from_the_plan_are_blocked(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, Plan::factory()->create([
            'feature_flags' => ['tasks' => true],
        ]));

        Route::middleware(['web', 'feature:teleportation'])
            ->get('/_test/unknown-feature', fn () => 'should not pass');

        $this->actingAs($user)
            ->get('/_test/unknown-feature')
            ->assertRedirect(route('client.pricing'));
    }
}
