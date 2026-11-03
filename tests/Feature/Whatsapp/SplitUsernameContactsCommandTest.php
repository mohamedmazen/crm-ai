<?php

namespace Tests\Feature\Whatsapp;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SplitUsernameContactsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeMergedContact(): array
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        $channelAccount = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'display_name' => 'Test WA',
            'phone_number_id' => 'PHONE_ID',
            'status' => 'active',
        ]);

        $placeholder = Contact::create([
            'workspace_id' => $workspace->id,
            'phone_e164' => '+',
            'opt_in_whatsapp' => true,
            'source' => 'whatsapp_inbound',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $channelAccount->id,
            'contact_id' => $placeholder->id,
            'status' => 'open',
            'external_thread_id' => '+',
        ]);

        $bsuidMessage = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'payload' => ['from' => 'US.11111111111111111111', 'from_user_id' => 'US.11111111111111111111'],
            'body' => 'From a username user',
            'status' => 'delivered',
            'sent_at' => now()->subMinutes(2),
        ]);

        $phoneMessage = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'payload' => ['from' => '15551230000'],
            'body' => 'From a phone user',
            'status' => 'delivered',
            'sent_at' => now()->subMinute(),
        ]);

        return compact('workspace', 'placeholder', 'conversation', 'bsuidMessage', 'phoneMessage');
    }

    #[Test]
    public function it_splits_merged_messages_onto_their_own_contacts(): void
    {
        $ctx = $this->makeMergedContact();

        $this->artisan('whatsapp:split-username-contacts')->assertExitCode(0);

        $bsuidContact = Contact::where('workspace_id', $ctx['workspace']->id)
            ->where('wa_user_id', 'US.11111111111111111111')
            ->sole();
        $phoneContact = Contact::where('workspace_id', $ctx['workspace']->id)
            ->where('phone_e164', '+15551230000')
            ->sole();

        $this->assertSame(
            $bsuidContact->id,
            Message::find($ctx['bsuidMessage']->id)->conversation->contact_id,
        );
        $this->assertSame(
            $phoneContact->id,
            Message::find($ctx['phoneMessage']->id)->conversation->contact_id,
        );

        $this->assertSoftDeleted('contacts', ['id' => $ctx['placeholder']->id]);
    }

    #[Test]
    public function dry_run_reports_without_writing(): void
    {
        $ctx = $this->makeMergedContact();

        $this->artisan('whatsapp:split-username-contacts', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNotSoftDeleted('contacts', ['id' => $ctx['placeholder']->id]);
        $this->assertSame(1, Contact::where('workspace_id', $ctx['workspace']->id)->count());
        $this->assertSame(
            $ctx['conversation']->id,
            Message::find($ctx['bsuidMessage']->id)->conversation_id,
        );
    }
}
