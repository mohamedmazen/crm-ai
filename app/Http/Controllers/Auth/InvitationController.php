<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Services\ClientAccess;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function __construct(private readonly ClientAccess $access) {}

    /**
     * Show the invitation acceptance page.
     */
    public function show(string $token): Response|RedirectResponse
    {
        $invitation = Invitation::where('token', $token)->first();

        if (! $invitation || ! $invitation->isPending()) {
            return redirect()->route('login')->withErrors(['invitation' => 'This invitation is invalid or has expired.']);
        }

        return Inertia::render('Auth/AcceptInvitation', [
            'token' => $token,
            'email' => $invitation->email,
            'client' => $invitation->client ? ['name' => $invitation->client->name] : null,
        ]);
    }

    /**
     * Accept the invitation and create/link the user account.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = Invitation::where('token', $token)->first();

        if (! $invitation || ! $invitation->isPending()) {
            return redirect()->route('login')->withErrors(['invitation' => 'This invitation is invalid or has expired.']);
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $user = User::where('email', $invitation->email)->first();

        // The role may have been deleted between sending and accepting the
        // invitation; fall back to staff rather than writing a slug that no
        // longer resolves to any permission.
        $clientRole = $invitation->client_role;
        if ($invitation->client && ! in_array($clientRole, $this->access->slugsFor($invitation->client), true)) {
            $clientRole = User::CLIENT_ROLE_STAFF;
        }
        $clientRole ??= User::CLIENT_ROLE_STAFF;

        if ($user) {
            // Existing user — just link them to the client if needed
            if ($invitation->client_id && ! $user->client_id) {
                $user->update([
                    'client_id' => $invitation->client_id,
                    'client_role' => $clientRole,
                ]);
            }
        } else {
            $client = $invitation->client;

            if ($client && ! $client->hasAvailableSeat(reservedSeats: 1)) {
                abort(402, __('Your plan does not have an available seat for this invitation.'));
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $invitation->email,
                'password' => Hash::make($request->password),
                'role' => 'client',
                'status' => 'active',
                'client_id' => $invitation->client_id,
                'client_role' => $clientRole,
                'email_verified_at' => now(),
            ]);

            event(new Registered($user));
        }

        $invitation->update(['accepted_at' => now()]);

        Auth::login($user);

        return redirect()->route('client.dashboard')->with('success', 'Welcome! Your invitation has been accepted.');
    }
}
