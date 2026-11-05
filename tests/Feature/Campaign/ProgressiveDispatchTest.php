<?php

namespace Tests\Feature\Campaign;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Broadcasting\Jobs\DispatchCampaignChunkJob;
use App\Modules\Broadcasting\Jobs\FinalizeCampaignJob;
use App\Modules\Broadcasting\Jobs\LaunchCampaignJob;
use App\Modules\Broadcasting\Jobs\SendCampaignMessageJob;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Broadcasting\Services\CampaignPersonalizer;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Segment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Progressive campaign dispatch.
 *
 * The old pipeline scheduled every chunk of a campaign at launch, so one
 * campaign could keep ~20 chunks (≈200 messages/second) in flight. These tests
 * pin the replacement contract: one chunk in flight per campaign, cursor-based
 * chaining, and idempotent sends when a campaign is paused and resumed.
 */
class ProgressiveDispatchTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Workspace} */
    private function ctx(): array
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        return [$user, $workspace];
    }

    /** @param array<string, mixed> $overrides */
    private function campaign(Workspace $workspace, array $overrides = []): Campaign
    {
        return Campaign::factory()->create(array_merge([
            'workspace_id' => $workspace->id,
            'channel' => 'sms',
            'status' => 'queued',
            'audience_type' => 'contact_list',
            'audience_ref' => null,
            'payload_json' => ['body' => 'Hi'],
        ], $overrides));
    }

    /**
     * @return Collection<int, Contact>
     */
    private function createRecipients(Campaign $campaign, int $count, string $status = 'queued'): Collection
    {
        $contacts = Contact::factory()->count($count)->create([
            'workspace_id' => $campaign->workspace_id,
            'opt_in_sms' => true,
        ]);

        $now = now();
        $rows = $contacts->map(fn (Contact $contact) => [
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            CampaignRecipient::insert($chunk);
        }

        return $contacts;
    }

    #[Test]
    public function launch_materialises_recipients_and_dispatches_exactly_one_chunk(): void
    {
        Queue::fake();

        [, $workspace] = $this->ctx();

        Contact::factory()->count(1200)->create([
            'workspace_id' => $workspace->id,
            'opt_in_sms' => true,
        ]);

        $campaign = $this->campaign($workspace);

        (new LaunchCampaignJob($campaign->id))->handle();

        $this->assertSame(1200, CampaignRecipient::where('campaign_id', $campaign->id)->count());
        $this->assertSame('sending', $campaign->fresh()->status);

        // One chunk in flight, not one per page.
        Queue::assertPushed(DispatchCampaignChunkJob::class, 1);
        Queue::assertPushed(
            DispatchCampaignChunkJob::class,
            fn (DispatchCampaignChunkJob $job) => $job->campaignId === $campaign->id && $job->afterId === 0,
        );

        Queue::assertPushed(FinalizeCampaignJob::class, 1);
    }

    #[Test]
    public function a_chunk_sends_one_page_and_chains_the_next_page_by_cursor(): void
    {
        Queue::fake();

        [, $workspace] = $this->ctx();
        $campaign = $this->campaign($workspace, ['status' => 'sending']);

        $this->createRecipients($campaign, 1200);

        (new DispatchCampaignChunkJob($campaign->id))->handle();

        Queue::assertPushed(SendCampaignMessageJob::class, DispatchCampaignChunkJob::BATCH_SIZE);

        $cursor = (int) CampaignRecipient::where('campaign_id', $campaign->id)
            ->orderBy('id')
            ->offset(DispatchCampaignChunkJob::BATCH_SIZE - 1)
            ->value('id');

        Queue::assertPushed(
            DispatchCampaignChunkJob::class,
            fn (DispatchCampaignChunkJob $job) => $job->afterId === $cursor,
        );

        // The next page is chained only after this page's paced window.
        Queue::assertPushed(
            DispatchCampaignChunkJob::class,
            fn (DispatchCampaignChunkJob $job) => $job->delay !== null
                && $job->delay->greaterThan(now()->addMinutes(5)),
        );

        // Simulate the chained page picking up the remaining 200 recipients.
        (new DispatchCampaignChunkJob($campaign->id, $cursor))->handle();

        Queue::assertPushed(SendCampaignMessageJob::class, 1200);

        // A short final page must not chain yet another (empty) chunk.
        Queue::assertPushed(DispatchCampaignChunkJob::class, 1);
    }

    #[Test]
    public function a_chunk_skips_recipients_that_already_settled_before_the_cursor(): void
    {
        Queue::fake();

        [, $workspace] = $this->ctx();
        $campaign = $this->campaign($workspace, ['status' => 'sending']);

        $contacts = $this->createRecipients($campaign, 3);
        $firstRecipientId = (int) CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('contact_id', $contacts->first()->id)
            ->value('id');

        (new DispatchCampaignChunkJob($campaign->id, $firstRecipientId))->handle();

        Queue::assertPushed(SendCampaignMessageJob::class, 2);
        Queue::assertPushed(
            SendCampaignMessageJob::class,
            fn (SendCampaignMessageJob $job) => $job->contactId !== $contacts->first()->id,
        );
    }

    #[Test]
    public function a_paused_campaign_does_not_dispatch_its_next_chunk(): void
    {
        Queue::fake();

        [, $workspace] = $this->ctx();
        $campaign = $this->campaign($workspace, ['status' => 'paused']);

        $this->createRecipients($campaign, 5);

        (new DispatchCampaignChunkJob($campaign->id))->handle();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_workspace_cannot_exceed_max_active_campaigns(): void
    {
        Queue::fake();
        config(['queue.tenant_limits.max_active_campaigns' => 1]);

        [, $workspace] = $this->ctx();

        $this->campaign($workspace, ['status' => 'sending', 'name' => 'Already sending']);

        Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'opt_in_sms' => true,
        ]);

        $waiting = $this->campaign($workspace, ['name' => 'Waiting']);

        $job = (new LaunchCampaignJob($waiting->id))->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased(60);
        $this->assertSame('queued', $waiting->fresh()->status);
        Queue::assertNotPushed(DispatchCampaignChunkJob::class);
    }

    #[Test]
    public function re_launching_a_fully_processed_campaign_settles_it_without_new_chunks(): void
    {
        Queue::fake();

        [, $workspace] = $this->ctx();
        $campaign = $this->campaign($workspace);

        $contact = Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'opt_in_sms' => true,
        ]);

        CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'status' => 'sent',
        ]);

        (new LaunchCampaignJob($campaign->id))->handle();

        Queue::assertNotPushed(DispatchCampaignChunkJob::class);
        Queue::assertPushed(FinalizeCampaignJob::class, 1);
    }

    #[Test]
    public function a_recipient_that_already_settled_is_never_sent_twice(): void
    {
        [, $workspace] = $this->ctx();

        $campaign = $this->campaign($workspace, ['status' => 'sending']);

        $contact = Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'opt_in_sms' => true,
        ]);

        $recipient = CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'status' => 'sent',
            'provider_message_id' => 'already-sent',
        ]);

        (new SendCampaignMessageJob($campaign->id, $contact->id, (int) $workspace->id))
            ->handle(app(CampaignPersonalizer::class));

        $recipient->refresh();
        $this->assertSame('sent', $recipient->status);
        $this->assertSame('already-sent', $recipient->provider_message_id);
    }

    #[Test]
    public function segment_audiences_are_materialised_with_channel_filters(): void
    {
        Queue::fake();

        [, $workspace] = $this->ctx();

        Contact::factory()->count(2)->create([
            'workspace_id' => $workspace->id,
            'country' => 'EG',
            'opt_in_sms' => true,
        ]);
        $optedOut = Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'country' => 'EG',
            'opt_in_sms' => false,
        ]);
        Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'country' => 'SA',
            'opt_in_sms' => true,
        ]);

        $segment = Segment::create([
            'workspace_id' => $workspace->id,
            'name' => 'Egypt',
            'type' => 'dynamic',
            'rules_json' => [
                'combinator' => 'AND',
                'conditions' => [['field' => 'country', 'operator' => '=', 'value' => 'EG']],
            ],
        ]);

        $campaign = $this->campaign($workspace, [
            'audience_type' => 'segment',
            'audience_ref' => (string) $segment->id,
        ]);

        (new LaunchCampaignJob($campaign->id))->handle();

        $this->assertSame(2, CampaignRecipient::where('campaign_id', $campaign->id)->count());
        $this->assertSame(
            0,
            CampaignRecipient::where('campaign_id', $campaign->id)
                ->where('contact_id', $optedOut->id)
                ->count(),
        );
    }

    #[Test]
    public function tag_audiences_are_materialised_with_channel_filters(): void
    {
        Queue::fake();

        [, $workspace] = $this->ctx();

        $tag = ContactTag::create([
            'workspace_id' => $workspace->id,
            'name' => 'VIP',
        ]);

        $tagged = Contact::factory()->count(2)->create([
            'workspace_id' => $workspace->id,
            'opt_in_sms' => true,
        ]);
        Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'opt_in_sms' => true,
        ]);

        $tag->contacts()->attach($tagged->pluck('id')->all());

        $campaign = $this->campaign($workspace, [
            'audience_type' => 'tag',
            'audience_ref' => (string) $tag->id,
        ]);

        (new LaunchCampaignJob($campaign->id))->handle();

        $this->assertSame(2, CampaignRecipient::where('campaign_id', $campaign->id)->count());
    }

    #[Test]
    public function the_finalizer_recovers_a_chain_that_stalled(): void
    {
        Queue::fake();

        [, $workspace] = $this->ctx();
        $campaign = $this->campaign($workspace, ['status' => 'sending']);

        $this->createRecipients($campaign, 3);
        $this->ageChain($campaign, minutes: 30);

        (new FinalizeCampaignJob($campaign->id))->handle();

        // The dead page is queued again from the first still-queued recipient.
        Queue::assertPushed(DispatchCampaignChunkJob::class, 1);
        Queue::assertPushed(
            DispatchCampaignChunkJob::class,
            fn (DispatchCampaignChunkJob $job) => $job->campaignId === $campaign->id,
        );

        // The finalizer keeps polling so it can settle the campaign later.
        Queue::assertPushed(FinalizeCampaignJob::class, 1);
    }

    #[Test]
    public function the_finalizer_does_not_recover_a_healthy_chain(): void
    {
        Queue::fake();

        [, $workspace] = $this->ctx();
        $campaign = $this->campaign($workspace, ['status' => 'sending']);

        $this->createRecipients($campaign, 3);

        (new FinalizeCampaignJob($campaign->id))->handle();

        Queue::assertNotPushed(DispatchCampaignChunkJob::class);
        Queue::assertPushed(FinalizeCampaignJob::class, 1);
    }

    #[Test]
    public function a_stalled_chain_is_recovered_only_once_per_stall_window(): void
    {
        Queue::fake();

        [, $workspace] = $this->ctx();
        $campaign = $this->campaign($workspace, ['status' => 'sending']);

        $this->createRecipients($campaign, 3);
        $this->ageChain($campaign, minutes: 30);

        (new FinalizeCampaignJob($campaign->id))->handle();
        (new FinalizeCampaignJob($campaign->id))->handle();

        // Both polls saw the same stale rows, but the cache claim allows one kick.
        Queue::assertPushed(DispatchCampaignChunkJob::class, 1);
    }

    #[Test]
    public function a_paused_campaign_is_never_recovered(): void
    {
        Queue::fake();

        [, $workspace] = $this->ctx();
        $campaign = $this->campaign($workspace, ['status' => 'paused']);

        $this->createRecipients($campaign, 3);
        $this->ageChain($campaign, minutes: 30);

        (new FinalizeCampaignJob($campaign->id))->handle();

        Queue::assertNothingPushed();
    }

    /**
     * Age both the campaign and its recipients past the stall threshold without
     * relying on time travel, which would also skew the delay assertions.
     */
    private function ageChain(Campaign $campaign, int $minutes): void
    {
        $past = now()->subMinutes($minutes);

        Campaign::where('id', $campaign->id)->update(['updated_at' => $past]);
        CampaignRecipient::where('campaign_id', $campaign->id)->update(['updated_at' => $past]);

        $campaign->refresh();
    }
}
