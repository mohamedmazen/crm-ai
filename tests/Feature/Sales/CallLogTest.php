<?php

namespace Tests\Feature\Sales;

use App\Events\CallCompleted;
use App\Events\CallInitiated;
use App\Events\CallRecordingStored;
use App\Models\Plan;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Models\CallLog;
use App\Modules\Sales\Models\CallProviderConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CallLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array{0: mixed, 1: mixed, 2: mixed} */
    private function actorWithPlan(mixed $callLogsFeature = true): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create([
            'feature_flags' => ['call_logs' => $callLogsFeature],
        ]);

        $this->attachPlanToClient($client, $plan);

        return [$user, $workspace, $client];
    }

    private function twilioConfig($workspace, string $authToken = 'test-auth-token'): CallProviderConfig
    {
        return CallProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => CallProviderConfig::PROVIDER_TWILIO,
            'credentials' => [
                'account_sid' => 'AC_test',
                'auth_token' => $authToken,
                'twiml_url' => 'https://handler.twilio.com/twiml/EH_test',
            ],
            'caller_id' => '+15550001111',
            'default' => true,
        ]);
    }

    private function makeCall($workspace, array $overrides = []): CallLog
    {
        return CallLog::create(array_merge([
            'workspace_id' => $workspace->id,
            'direction' => CallLog::DIRECTION_OUTBOUND,
            'from_number' => '+15550001111',
            'to_number' => '+15550002222',
            'status' => CallLog::STATUS_INITIATED,
            'provider' => 'twilio',
            'provider_sid' => 'CA_existing',
        ], $overrides));
    }

    /** @param array<string, string> $params */
    private function twilioSignature(string $url, array $params, string $authToken): string
    {
        ksort($params);

        $data = $url;
        foreach ($params as $key => $value) {
            $data .= $key.$value;
        }

        return base64_encode(hash_hmac('sha1', $data, $authToken, true));
    }

    #[Test]
    public function call_logs_are_gated_by_the_plan(): void
    {
        [$user] = $this->actorWithPlan(callLogsFeature: false);

        $this->actingAs($user)
            ->get(route('client.sales.calls.index'))
            ->assertRedirect(route('client.pricing'))
            ->assertSessionHas('upgrade_required', true);
    }

    #[Test]
    public function a_manual_call_is_logged_against_the_lead(): void
    {
        [$user, $workspace] = $this->actorWithPlan();
        Event::fake([CallCompleted::class]);

        $lead = Lead::factory()->create(['workspace_id' => $workspace->id, 'phone' => '+15550002222']);

        $this->actingAs($user)
            ->post(route('client.sales.calls.store'), [
                'lead_id' => $lead->id,
                'direction' => CallLog::DIRECTION_OUTBOUND,
                'status' => CallLog::STATUS_COMPLETED,
                'duration_secs' => 95,
                'notes' => 'Discussed pricing',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $call = CallLog::where('workspace_id', $workspace->id)->firstOrFail();

        $this->assertSame($lead->id, $call->lead_id);
        $this->assertSame('+15550002222', $call->to_number);
        $this->assertSame(95, $call->duration_secs);
        $this->assertSame($user->id, $call->user_id);

        Event::assertDispatched(CallCompleted::class, fn (CallCompleted $event) => $event->callId === $call->id
            && $event->leadId === $lead->id
            && $event->durationSecs === 95);
    }

    #[Test]
    public function the_index_only_lists_the_current_workspaces_calls(): void
    {
        [$user, $workspace] = $this->actorWithPlan();
        [, $otherWorkspace] = $this->actorWithPlan();

        $mine = $this->makeCall($workspace);
        $this->makeCall($otherWorkspace);

        $this->actingAs($user)
            ->get(route('client.sales.calls.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Calls/Index')
                ->has('calls.data', 1)
                ->where('calls.data.0.id', $mine->id));
    }

    #[Test]
    public function click_to_call_requires_a_connected_provider(): void
    {
        [$user, $workspace] = $this->actorWithPlan();
        $lead = Lead::factory()->create(['workspace_id' => $workspace->id, 'phone' => '+15550002222']);

        $this->actingAs($user)
            ->post(route('client.sales.calls.initiate'), ['lead_id' => $lead->id])
            ->assertSessionHasErrors('lead_id');

        $this->assertDatabaseCount('call_logs', 0);
    }

    #[Test]
    public function click_to_call_places_the_call_and_logs_it(): void
    {
        [$user, $workspace] = $this->actorWithPlan();
        $this->twilioConfig($workspace);
        Event::fake([CallInitiated::class]);

        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'CA_new_call'], 201),
        ]);

        $lead = Lead::factory()->create(['workspace_id' => $workspace->id, 'phone' => '+15550002222']);

        $this->actingAs($user)
            ->post(route('client.sales.calls.initiate'), ['lead_id' => $lead->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $call = CallLog::where('workspace_id', $workspace->id)->firstOrFail();

        $this->assertSame('CA_new_call', $call->provider_sid);
        $this->assertSame('+15550002222', $call->to_number);
        $this->assertSame('+15550001111', $call->from_number);
        $this->assertSame(CallLog::STATUS_INITIATED, $call->status);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'Accounts/AC_test/Calls.json')
            && $request['To'] === '+15550002222'
            && $request['From'] === '+15550001111'
            && $request['Url'] === 'https://handler.twilio.com/twiml/EH_test');

        Event::assertDispatched(CallInitiated::class, fn (CallInitiated $event) => $event->callId === $call->id
            && $event->leadId === $lead->id
            && $event->providerSid === 'CA_new_call');
    }

    #[Test]
    public function a_webhook_with_an_invalid_signature_is_rejected(): void
    {
        [, $workspace] = $this->actorWithPlan();
        $config = $this->twilioConfig($workspace);
        $call = $this->makeCall($workspace, ['provider_sid' => 'CA_secured']);

        $this->post(
            route('webhooks.calls.status', ['provider' => 'twilio', 'config' => $config->id]),
            ['CallSid' => $call->provider_sid, 'CallStatus' => 'completed', 'CallDuration' => '30'],
            ['X-Twilio-Signature' => 'not-a-real-signature'],
        )->assertForbidden();

        $this->assertSame(CallLog::STATUS_INITIATED, $call->fresh()->status);
    }

    #[Test]
    public function a_signed_webhook_completes_the_call(): void
    {
        [, $workspace] = $this->actorWithPlan();
        $config = $this->twilioConfig($workspace);
        Event::fake([CallCompleted::class]);

        $call = $this->makeCall($workspace, ['provider_sid' => 'CA_secured']);

        $url = route('webhooks.calls.status', ['provider' => 'twilio', 'config' => $config->id]);
        $params = [
            'CallSid' => 'CA_secured',
            'CallStatus' => 'completed',
            'CallDuration' => '42',
            'From' => '+15550001111',
            'To' => '+15550002222',
        ];

        $this->post($url, $params, [
            'X-Twilio-Signature' => $this->twilioSignature($url, $params, 'test-auth-token'),
        ])->assertOk()->assertJson(['ok' => true]);

        $call->refresh();

        $this->assertSame(CallLog::STATUS_COMPLETED, $call->status);
        $this->assertSame(42, $call->duration_secs);
        $this->assertTrue($call->isTerminal());

        Event::assertDispatched(CallCompleted::class, fn (CallCompleted $event) => $event->callId === $call->id
            && $event->status === 'completed'
            && $event->durationSecs === 42);
    }

    #[Test]
    public function a_webhook_only_updates_a_call_in_the_signing_workspace(): void
    {
        [, $workspace] = $this->actorWithPlan();
        $config = $this->twilioConfig($workspace);

        [, $otherWorkspace] = $this->actorWithPlan();
        $this->twilioConfig($otherWorkspace);

        // Both workspaces happen to hold a call with the same provider sid. The
        // foreign call is created first so an unscoped sid lookup would hit it.
        $foreignCall = $this->makeCall($otherWorkspace, ['provider_sid' => 'CA_shared']);
        $ownCall = $this->makeCall($workspace, ['provider_sid' => 'CA_shared']);

        $url = route('webhooks.calls.status', ['provider' => 'twilio', 'config' => $config->id]);
        $params = [
            'CallSid' => 'CA_shared',
            'CallStatus' => 'completed',
            'CallDuration' => '30',
        ];

        $this->post($url, $params, [
            'X-Twilio-Signature' => $this->twilioSignature($url, $params, 'test-auth-token'),
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(CallLog::STATUS_COMPLETED, $ownCall->fresh()->status);
        $this->assertSame(CallLog::STATUS_INITIATED, $foreignCall->fresh()->status);
    }

    #[Test]
    public function a_recording_callback_stores_the_url_without_regressing_the_status(): void
    {
        [, $workspace] = $this->actorWithPlan();
        $config = $this->twilioConfig($workspace);
        Event::fake([CallRecordingStored::class, CallCompleted::class]);

        $call = $this->makeCall($workspace, [
            'provider_sid' => 'CA_recorded',
            'status' => CallLog::STATUS_COMPLETED,
            'duration_secs' => 60,
        ]);

        $url = route('webhooks.calls.status', ['provider' => 'twilio', 'config' => $config->id]);
        $params = ['CallSid' => 'CA_recorded', 'RecordingUrl' => 'https://api.twilio.com/recordings/RE_1'];

        $this->post($url, $params, [
            'X-Twilio-Signature' => $this->twilioSignature($url, $params, 'test-auth-token'),
        ])->assertOk();

        $call->refresh();

        $this->assertSame(CallLog::STATUS_COMPLETED, $call->status);
        $this->assertSame('https://api.twilio.com/recordings/RE_1.mp3', $call->recording_url);

        Event::assertDispatched(CallRecordingStored::class, fn (CallRecordingStored $event) => $event->callId === $call->id
            && str_ends_with($event->recordingUrl, 'RE_1.mp3'));
        Event::assertNotDispatched(CallCompleted::class);
    }

    #[Test]
    public function call_settings_store_encrypted_credentials_and_make_the_provider_default(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $this->actingAs($user)
            ->post(route('client.sales.calls.settings.update'), [
                'provider' => 'twilio',
                'caller_id' => '+15550001111',
                'credentials' => [
                    'account_sid' => 'AC_test',
                    'auth_token' => 'super-secret',
                    'twiml_url' => 'https://handler.twilio.com/twiml/EH_test',
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $config = CallProviderConfig::where('workspace_id', $workspace->id)->firstOrFail();

        $this->assertTrue($config->default);
        $this->assertSame('+15550001111', $config->caller_id);
        $this->assertSame('super-secret', $config->credentials['auth_token']);
        $this->assertStringNotContainsString('super-secret', (string) $config->getRawOriginal('credentials'));
    }

    #[Test]
    public function a_call_from_another_workspace_cannot_be_deleted(): void
    {
        [$user] = $this->actorWithPlan();
        [, $otherWorkspace] = $this->actorWithPlan();
        $foreignCall = $this->makeCall($otherWorkspace);

        $this->actingAs($user)
            ->delete(route('client.sales.calls.destroy', $foreignCall->id))
            ->assertForbidden();

        $this->assertDatabaseHas('call_logs', ['id' => $foreignCall->id]);
    }

    #[Test]
    public function a_logged_call_is_listed_on_the_lead_detail(): void
    {
        [$user, $workspace] = $this->actorWithPlan();
        $lead = Lead::factory()->create(['workspace_id' => $workspace->id, 'phone' => '+15550002222']);

        $call = $this->makeCall($workspace, [
            'lead_id' => $lead->id,
            'status' => CallLog::STATUS_COMPLETED,
            'duration_secs' => 75,
        ]);

        $this->actingAs($user)
            ->get(route('client.leads.pipeline.index', ['lead' => $lead->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('leadDetail.calls.0.id', $call->id)
                ->where('leadDetail.calls.0.status', CallLog::STATUS_COMPLETED)
                ->where('leadDetail.calls.0.duration_secs', 75)
            );
    }

    #[Test]
    public function the_lead_detail_omits_calls_when_the_plan_has_no_call_log(): void
    {
        [$user, $workspace] = $this->actorWithPlan(callLogsFeature: false);
        $lead = Lead::factory()->create(['workspace_id' => $workspace->id, 'phone' => '+15550002222']);

        $this->makeCall($workspace, ['lead_id' => $lead->id]);

        $this->actingAs($user)
            ->get(route('client.leads.pipeline.index', ['lead' => $lead->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('leadDetail.calls', []));
    }
}
