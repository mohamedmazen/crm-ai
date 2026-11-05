<?php

namespace Tests\Feature\Campaign;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Broadcasting\Jobs\SendCampaignMessageJob;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Broadcasting\Services\CampaignPersonalizer;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_does_not_send_a_campaign_message_to_a_contact_from_another_workspace(): void
    {
        Mail::fake();

        [$workspace] = $this->workspaceFor();
        [$otherWorkspace] = $this->workspaceFor();

        $campaign = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'email',
            'status' => 'queued',
            'payload_json' => ['subject' => 'Hi', 'body' => 'Hello'],
        ]);

        // A contact that belongs to a different tenant, referenced by the payload.
        $foreignContact = Contact::factory()->create(['workspace_id' => $otherWorkspace->id]);

        $recipient = CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $foreignContact->id,
            'status' => 'queued',
        ]);

        (new SendCampaignMessageJob($campaign->id, $foreignContact->id))
            ->handle(app(CampaignPersonalizer::class));

        $recipient->refresh();

        $this->assertSame('failed', $recipient->status);
        $this->assertSame('tenant_mismatch', $recipient->failed_reason);
        $this->assertNull($recipient->sent_at);
        $this->assertSame(0, Message::count());
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    #[Test]
    public function it_marks_the_recipient_failed_when_the_contact_no_longer_exists(): void
    {
        Mail::fake();

        [$workspace] = $this->workspaceFor();

        $campaign = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'email',
            'status' => 'queued',
            'payload_json' => ['subject' => 'Hi', 'body' => 'Hello'],
        ]);

        $recipient = CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => 999999,
            'status' => 'queued',
        ]);

        (new SendCampaignMessageJob($campaign->id, 999999))
            ->handle(app(CampaignPersonalizer::class));

        $recipient->refresh();

        $this->assertSame('failed', $recipient->status);
        $this->assertSame('tenant_mismatch', $recipient->failed_reason);
        $this->assertSame(0, Message::count());
        Mail::assertNothingQueued();
    }

    /** @return array{0: Workspace} */
    private function workspaceFor(): array
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        return [$workspace];
    }
}
