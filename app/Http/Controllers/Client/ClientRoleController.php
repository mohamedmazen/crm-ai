<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientRole;
use App\Models\User;
use App\Services\ClientAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant-defined team roles and the permissions behind them.
 *
 * The three built-in roles are read-only here (their semantics are relied on by
 * last-administrator protection), so only the client's own roles can be created,
 * edited or deleted. Every action is scoped to the acting administrator's
 * client: a role id from another tenant resolves to 404, never to an edit.
 */
class ClientRoleController extends Controller
{
    public function __construct(private readonly ClientAccess $access) {}

    public function index(Request $request): Response
    {
        $client = $this->clientOrFail($request);

        $memberCounts = User::where('client_id', $client->id)
            ->selectRaw('client_role, COUNT(*) as total')
            ->groupBy('client_role')
            ->pluck('total', 'client_role');

        $roles = array_map(function (array $role) use ($memberCounts) {
            $role['members_count'] = (int) ($memberCounts[$role['slug']] ?? 0);

            return $role;
        }, $this->access->roleOptions($client));

        return Inertia::render('client/Team/Roles', [
            'roles' => $roles,
            'catalog' => $this->access->catalogForUi(),
            'client' => ['id' => $client->id, 'name' => $client->name],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $client = $this->clientOrFail($request);

        $validated = $this->validateRole($request);

        ClientRole::create([
            'client_id' => $client->id,
            'name' => $validated['name'],
            'slug' => $this->access->generateSlug($client, $validated['name']),
            'description' => $validated['description'] ?? null,
            'permissions' => $validated['permissions'] ?? [],
        ]);

        return back()->with('success', __('Role created.'));
    }

    public function update(Request $request, ClientRole $role): RedirectResponse
    {
        $client = $this->clientOrFail($request);

        abort_unless($role->client_id === $client->id, 404);

        $validated = $this->validateRole($request);

        $role->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'permissions' => $validated['permissions'] ?? [],
        ]);

        return back()->with('success', __('Role updated.'));
    }

    public function destroy(Request $request, ClientRole $role): RedirectResponse
    {
        $client = $this->clientOrFail($request);

        abort_unless($role->client_id === $client->id, 404);

        $inUse = User::where('client_id', $client->id)
            ->where('client_role', $role->slug)
            ->exists();

        if ($inUse) {
            return back()->with('error', __('Reassign the team members using this role before deleting it.'));
        }

        $role->delete();

        return back()->with('success', __('Role deleted.'));
    }

    private function clientOrFail(Request $request): Client
    {
        $user = $request->user();

        if (! $user->client_id || ! $user->isClientAdministrator()) {
            abort(403, __('Only client administrators can manage roles.'));
        }

        $client = $user->client;

        if (! $client) {
            abort(404);
        }

        return $client;
    }

    /**
     * @return array{name: string, description?: string|null, permissions?: list<string>}
     */
    private function validateRole(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in($this->access->allPermissionKeys())],
        ]);

        /** @var list<string> $permissions */
        $permissions = array_values(array_unique($validated['permissions'] ?? []));

        $validated['permissions'] = $permissions;

        return $validated;
    }
}
