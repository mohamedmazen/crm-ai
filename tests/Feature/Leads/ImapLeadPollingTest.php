<?php

namespace Tests\Feature\Leads;

use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Models\LeadSource;
use App\Modules\Sales\Services\ImapLeadParser;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImapLeadPollingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function imap_polling_is_scheduled_every_five_minutes(): void
    {
        $this->app->make(Kernel::class)->bootstrap();

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'leads:fetch-imap'));

        $this->assertNotNull($event, 'leads:fetch-imap is not scheduled.');
        $this->assertSame('fetch-imap-leads', $event->description);
        $this->assertSame('*/5 * * * *', $event->expression);
    }

    #[Test]
    public function a_repeated_poll_does_not_create_a_second_lead_for_the_same_message(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $source = LeadSource::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support Inbox',
            'type' => LeadSource::TYPE_IMAP,
            'status' => LeadSource::STATUS_ACTIVE,
        ]);

        $parser = app(ImapLeadParser::class);

        $email = [
            'body' => "Name: Sonny Corleone\nPhone: +15550001111\nPlease call me back.",
            'subject' => 'Interested in your service',
            'from_email' => 'sonny@corleone.it',
            'from_name' => 'Sonny Corleone',
            'message_id' => '<abc123@mail.example.com>',
        ];

        $first = $parser->ingest($workspace->id, $email, $source);
        $second = $parser->ingest($workspace->id, $email, $source);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Lead::where('workspace_id', $workspace->id)->count());
    }

    #[Test]
    public function a_new_message_id_still_creates_a_new_lead(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $source = LeadSource::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support Inbox',
            'type' => LeadSource::TYPE_IMAP,
            'status' => LeadSource::STATUS_ACTIVE,
        ]);

        $parser = app(ImapLeadParser::class);

        $base = [
            'body' => 'Name: Fredo Corleone',
            'subject' => 'Hello',
            'from_email' => 'fredo@corleone.it',
            'from_name' => 'Fredo Corleone',
        ];

        $parser->ingest($workspace->id, [...$base, 'message_id' => '<one@mail.example.com>'], $source);
        $parser->ingest($workspace->id, [...$base, 'message_id' => '<two@mail.example.com>'], $source);

        $this->assertSame(2, Lead::where('workspace_id', $workspace->id)->count());
    }
}
