<?php

namespace Tests\Feature\Plan;

use App\Models\Invitation;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SeatLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_is_allowed_when_a_seat_is_available(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, $this->planWithSeats(2));

        $this->actingAs($user)
            ->post(route('client.invitations.store'), $this->invitationPayload('invitee@example.com'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('invitations', [
            'client_id' => $client->id,
            'email' => 'invitee@example.com',
        ]);
    }

    public function test_invitation_is_blocked_when_all_seats_are_used(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, $this->planWithSeats(1));

        $response = $this->actingAs($user)
            ->post(route('client.invitations.store'), $this->invitationPayload('invitee@example.com'));

        $response->assertRedirect('/billing');
        $response->assertSessionHas('upgrade_required', true);
        $this->assertDatabaseMissing('invitations', ['email' => 'invitee@example.com']);
    }

    public function test_pending_invitations_count_towards_the_seat_cap(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, $this->planWithSeats(2));

        $this->createInvitation($client->id, $user->id, 'pending@example.com');

        $this->assertSame(2, $client->seatUsage());

        $this->actingAs($user)
            ->post(route('client.invitations.store'), $this->invitationPayload('second@example.com'))
            ->assertRedirect('/billing')
            ->assertSessionHas('upgrade_required', true);

        $this->assertDatabaseMissing('invitations', ['email' => 'second@example.com']);
    }

    public function test_expired_invitations_do_not_count_towards_the_seat_cap(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, $this->planWithSeats(2));

        $this->createInvitation($client->id, $user->id, 'expired@example.com', now()->subDay());

        $this->assertSame(1, $client->seatUsage());

        $this->actingAs($user)
            ->post(route('client.invitations.store'), $this->invitationPayload('fresh@example.com'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('invitations', ['email' => 'fresh@example.com']);
    }

    public function test_adding_a_team_member_is_blocked_when_all_seats_are_used(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, $this->planWithSeats(1));

        $this->actingAs($user)
            ->post(route('client.team.store'), [
                'name' => 'New Teammate',
                'email' => 'teammate@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'client_role' => User::CLIENT_ROLE_STAFF,
                'status' => User::STATUS_ACTIVE,
            ])
            ->assertRedirect('/billing')
            ->assertSessionHas('upgrade_required', true);

        $this->assertDatabaseMissing('users', ['email' => 'teammate@example.com']);
    }

    public function test_unlimited_plans_do_not_block_invitations(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, Plan::factory()->create([
            'limits' => ['users' => null],
        ]));

        $this->actingAs($user)
            ->post(route('client.invitations.store'), $this->invitationPayload('invitee@example.com'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('invitations', ['email' => 'invitee@example.com']);
    }

    public function test_accepting_an_invitation_consumes_its_reserved_seat(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, $this->planWithSeats(2));

        $invitation = $this->createInvitation($client->id, $user->id, 'invitee@example.com');

        $this->post(route('auth.invitations.accept', $invitation->token), [
            'name' => 'Invited Person',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('client.dashboard'));

        $this->assertDatabaseHas('users', [
            'email' => 'invitee@example.com',
            'client_id' => $client->id,
        ]);

        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertSame(2, $client->fresh()->seatUsage());
    }

    public function test_accepting_an_invitation_is_blocked_when_the_reserved_seat_would_exceed_the_cap(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, $this->planWithSeats(1));

        // Create the invite directly so the middleware cannot reject it first.
        $invitation = $this->createInvitation($client->id, $user->id, 'invitee@example.com');

        $this->post(route('auth.invitations.accept', $invitation->token), [
            'name' => 'Invited Person',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(402);

        $this->assertDatabaseMissing('users', ['email' => 'invitee@example.com']);
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    private function planWithSeats(int $seats): Plan
    {
        return Plan::factory()->create(['limits' => ['users' => $seats]]);
    }

    /**
     * @return array<string, string>
     */
    private function invitationPayload(string $email): array
    {
        return [
            'email' => $email,
            'client_role' => User::CLIENT_ROLE_STAFF,
        ];
    }

    private function createInvitation(int $clientId, int $invitedBy, string $email, mixed $expiresAt = null): Invitation
    {
        return Invitation::create([
            'client_id' => $clientId,
            'email' => $email,
            'client_role' => User::CLIENT_ROLE_STAFF,
            'token' => Str::random(64),
            'invited_by' => $invitedBy,
            'expires_at' => $expiresAt ?? now()->addDays(7),
        ]);
    }
}
