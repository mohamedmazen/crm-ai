<?php

namespace Tests\Feature;

use App\Models\ClientSetting;
use App\Models\SystemSetting;
use App\Models\User;
use App\Modules\Leads\Models\Lead;
use App\Modules\Shared\Jobs\PurgeErasureRequestJob;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\GdprErasureRequest;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\GdprService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GdprTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['filesystems.default' => 'local']);
    }

    private function createContactWithConversation(int $workspaceId, string $email): array
    {
        $contact = Contact::factory()->create([
            'workspace_id' => $workspaceId,
            'email' => $email,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspaceId,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'Hello',
        ]);

        return [$contact, $conversation, $message];
    }

    #[Test]
    public function a_client_administrator_can_open_the_privacy_page(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        GdprErasureRequest::create([
            'workspace_id' => $workspace->id,
            'email' => 'pending@example.com',
            'status' => GdprErasureRequest::STATUS_PENDING,
            'due_at' => now()->addDays(30),
        ]);

        $this->actingAs($user)
            ->get(route('client.settings.gdpr'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('client/Settings/Gdpr')
                ->has('requests', 1)
                ->where('requests.0.email', 'pending@example.com')
                ->where('graceDays', GdprService::ERASURE_GRACE_DAYS)
            );
    }

    #[Test]
    public function a_staff_member_cannot_open_the_privacy_page(): void
    {
        ['user' => $staff] = $this->createWorkspaceContext([], ['client_role' => User::CLIENT_ROLE_STAFF]);

        $this->actingAs($staff)
            ->get(route('client.settings.gdpr'))
            ->assertForbidden();
    }

    #[Test]
    public function an_erasure_request_gets_a_thirty_day_grace_period(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->post(route('client.settings.gdpr.erasure.store'), [
                'email' => 'Victim@Example.com',
                'notes' => 'Ticket #42',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $request = GdprErasureRequest::firstOrFail();

        $this->assertSame('victim@example.com', $request->email);
        $this->assertSame(GdprErasureRequest::STATUS_PENDING, $request->status);
        $this->assertTrue($request->due_at->isSameDay(now()->addDays(30)));
        $this->assertSame('Ticket #42', $request->notes);
    }

    #[Test]
    public function a_duplicate_request_is_not_created_twice(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.settings.gdpr.erasure.store'), ['email' => 'dup@example.com']);
        $this->actingAs($user)->post(route('client.settings.gdpr.erasure.store'), ['email' => 'DUP@example.com']);

        $this->assertSame(1, GdprErasureRequest::count());
    }

    #[Test]
    public function a_pending_request_can_be_cancelled(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $request = GdprErasureRequest::create([
            'workspace_id' => $workspace->id,
            'email' => 'keep@example.com',
            'status' => GdprErasureRequest::STATUS_PENDING,
            'due_at' => now()->addDays(30),
        ]);

        $this->actingAs($user)
            ->delete(route('client.settings.gdpr.erasure.cancel', $request))
            ->assertRedirect()
            ->assertSessionHas('success');

        $request->refresh();

        $this->assertSame(GdprErasureRequest::STATUS_CANCELLED, $request->status);
        $this->assertNotNull($request->cancelled_at);
    }

    #[Test]
    public function a_request_from_another_workspace_cannot_be_cancelled(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        ['user' => $otherAdmin] = $this->createWorkspaceContext();

        $request = GdprErasureRequest::create([
            'workspace_id' => $workspace->id,
            'email' => 'other@example.com',
            'status' => GdprErasureRequest::STATUS_PENDING,
            'due_at' => now()->addDays(30),
        ]);

        $this->actingAs($otherAdmin)
            ->delete(route('client.settings.gdpr.erasure.cancel', $request))
            ->assertNotFound();

        $this->assertSame(GdprErasureRequest::STATUS_PENDING, $request->fresh()->status);
    }

    #[Test]
    public function executing_a_request_deletes_inbox_data_and_anonymises_leads(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        [$contact, $conversation, $message] = $this->createContactWithConversation($workspace->id, 'erase@example.com');

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'email' => 'erase@example.com',
            'phone' => '+15550001111',
            'name' => 'Acme Ltd',
        ]);

        $request = GdprErasureRequest::create([
            'workspace_id' => $workspace->id,
            'email' => 'erase@example.com',
            'status' => GdprErasureRequest::STATUS_PENDING,
            'due_at' => now()->subDay(),
        ]);

        $result = app(GdprService::class)->execute($request);

        $this->assertSame(1, $result['contacts']);
        $this->assertSame(1, $result['conversations']);
        $this->assertSame(1, $result['messages']);
        $this->assertSame(1, $result['leads']);

        $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);
        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);

        $lead->refresh();
        $this->assertNull($lead->email);
        $this->assertNull($lead->phone);
        $this->assertSame('Erased contact', $lead->name);

        $this->assertSame(GdprErasureRequest::STATUS_COMPLETED, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->completed_at);
    }

    #[Test]
    public function a_cancelled_request_never_deletes_anything(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        [$contact] = $this->createContactWithConversation($workspace->id, 'safe@example.com');

        $request = GdprErasureRequest::create([
            'workspace_id' => $workspace->id,
            'email' => 'safe@example.com',
            'status' => GdprErasureRequest::STATUS_CANCELLED,
            'due_at' => now()->subDay(),
            'cancelled_at' => now(),
        ]);

        app(GdprService::class)->execute($request);

        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
    }

    #[Test]
    public function the_erasure_job_does_nothing_before_the_deadline(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        [$contact] = $this->createContactWithConversation($workspace->id, 'later@example.com');

        $request = GdprErasureRequest::create([
            'workspace_id' => $workspace->id,
            'email' => 'later@example.com',
            'status' => GdprErasureRequest::STATUS_PENDING,
            'due_at' => now()->addDays(10),
        ]);

        (new PurgeErasureRequestJob($request->id))->handle(app(GdprService::class));

        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
        $this->assertSame(GdprErasureRequest::STATUS_PENDING, $request->fresh()->status);
    }

    #[Test]
    public function the_dpa_can_be_downloaded_as_a_pdf(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext(['name' => 'Acme Trading']);

        $response = $this->actingAs($user)->get(route('client.settings.gdpr.dpa'));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $response->assertDownload('dpa-'.now()->format('Y-m-d').'.pdf');
    }

    #[Test]
    public function export_retention_deletes_only_archives_past_the_window(): void
    {
        SystemSetting::set('privacy.export_retention_days', '7');

        Storage::disk('local')->put('exports/1/old.zip', 'old');
        Storage::disk('local')->put('exports/1/new.zip', 'new');

        $oldPath = Storage::disk('local')->path('exports/1/old.zip');
        touch($oldPath, now()->subDays(10)->getTimestamp());

        $deleted = app(GdprService::class)->applyExportRetention();

        $this->assertSame(1, $deleted);
        Storage::disk('local')->assertMissing('exports/1/old.zip');
        Storage::disk('local')->assertExists('exports/1/new.zip');
    }

    #[Test]
    public function export_retention_can_be_disabled_with_zero(): void
    {
        Storage::disk('local')->put('exports/1/old.zip', 'old');
        $path = Storage::disk('local')->path('exports/1/old.zip');
        touch($path, now()->subDays(90)->getTimestamp());

        $this->assertSame(0, app(GdprService::class)->applyExportRetention(0));
        Storage::disk('local')->assertExists('exports/1/old.zip');
    }

    #[Test]
    public function a_workspace_can_override_the_retention_window(): void
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();

        ClientSetting::set($client->id, 'export_retention_days', '3');

        Storage::disk('local')->put("exports/{$workspace->id}/five-days.zip", 'dump');
        $path = Storage::disk('local')->path("exports/{$workspace->id}/five-days.zip");
        touch($path, now()->subDays(5)->getTimestamp());

        $this->assertSame(1, app(GdprService::class)->applyExportRetention());
        Storage::disk('local')->assertMissing("exports/{$workspace->id}/five-days.zip");
    }

    #[Test]
    public function a_workspace_can_disable_retention_with_zero(): void
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();

        ClientSetting::set($client->id, 'export_retention_days', '0');

        Storage::disk('local')->put("exports/{$workspace->id}/ancient.zip", 'dump');
        $path = Storage::disk('local')->path("exports/{$workspace->id}/ancient.zip");
        touch($path, now()->subDays(365)->getTimestamp());

        $this->assertSame(0, app(GdprService::class)->applyExportRetention());
        Storage::disk('local')->assertExists("exports/{$workspace->id}/ancient.zip");
    }

    #[Test]
    public function the_scheduled_command_dispatches_only_due_requests(): void
    {
        Bus::fake();

        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $due = GdprErasureRequest::create([
            'workspace_id' => $workspace->id,
            'email' => 'due@example.com',
            'status' => GdprErasureRequest::STATUS_PENDING,
            'due_at' => now()->subHour(),
        ]);

        $future = GdprErasureRequest::create([
            'workspace_id' => $workspace->id,
            'email' => 'future@example.com',
            'status' => GdprErasureRequest::STATUS_PENDING,
            'due_at' => now()->addDay(),
        ]);

        $this->artisan('gdpr:process')->assertSuccessful();

        Bus::assertDispatchedTimes(PurgeErasureRequestJob::class, 1);
        Bus::assertDispatched(PurgeErasureRequestJob::class, fn (PurgeErasureRequestJob $job): bool => $job->requestId === $due->id);
        Bus::assertNotDispatched(PurgeErasureRequestJob::class, fn (PurgeErasureRequestJob $job): bool => $job->requestId === $future->id);
    }
}
