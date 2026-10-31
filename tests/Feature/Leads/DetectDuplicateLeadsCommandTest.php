<?php

namespace Tests\Feature\Leads;

use App\Models\Plan;
use App\Modules\Leads\Jobs\DetectDuplicatesJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DetectDuplicateLeadsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_queues_detection_only_for_workspaces_whose_plan_includes_duplicates(): void
    {
        ['client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create([
            'feature_flags' => ['duplicates' => true],
        ]));

        ['client' => $clientWithout, 'workspace' => $workspaceWithout] = $this->createWorkspaceContext();
        $this->attachPlanToClient($clientWithout, Plan::factory()->create([
            'feature_flags' => ['duplicates' => false],
        ]));

        Queue::fake();

        $this->artisan('leads:detect-duplicates')
            ->expectsOutputToContain('Queued duplicate detection for 1 workspace(s).')
            ->assertSuccessful();

        Queue::assertPushed(DetectDuplicatesJob::class, function (DetectDuplicatesJob $job) use ($workspace) {
            return $job->workspaceId === $workspace->id;
        });

        Queue::assertNotPushed(DetectDuplicatesJob::class, function (DetectDuplicatesJob $job) use ($workspaceWithout) {
            return $job->workspaceId === $workspaceWithout->id;
        });
    }
}
