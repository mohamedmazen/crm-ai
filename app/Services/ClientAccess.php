<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientRole;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Resolves what a client user is allowed to do.
 *
 * Client roles are module/action permissions (`inbox.view`, `sales.write`) taken
 * from config/client_permissions.php. The three built-in roles are computed from
 * that catalogue and never stored; tenant-defined roles are `client_roles` rows
 * scoped to one client.
 *
 * Everything is cached on the instance, and the container binds the class as a
 * scoped singleton, so a request resolves a role at most once (Octane-safe: the
 * scope is flushed between requests).
 */
class ClientAccess
{
    /** Returned when a route belongs to no catalogue module: fail closed. */
    public const UNMAPPED = '__unmapped__';

    /** @var array<string, list<string>> */
    private array $permissions = [];

    /** @var array<string, string>|null prefix => module, longest first */
    private ?array $prefixes = null;

    /** @var list<string>|null */
    private ?array $allKeys = null;

    // ---------------------------------------------------------------------
    // Permissions
    // ---------------------------------------------------------------------

    /**
     * The effective permission keys for a user, or [] when the role is unknown
     * (a role deleted while still assigned), which fails closed on purpose.
     *
     * @return list<string>
     */
    public function permissionsFor(?User $user): array
    {
        if (! $user instanceof User || ! $user->client_id) {
            return [];
        }

        $slug = $user->client_role ?: User::CLIENT_ROLE_STAFF;
        $cacheKey = $user->client_id.'|'.$slug;

        if (isset($this->permissions[$cacheKey])) {
            return $this->permissions[$cacheKey];
        }

        $system = config('client_permissions.system_roles.'.$slug);

        if (is_array($system)) {
            return $this->permissions[$cacheKey] = $this->systemPermissions($system);
        }

        $role = collect($this->customRoles($user->client_id))->firstWhere('slug', $slug);

        return $this->permissions[$cacheKey] = $role
            ? $this->normalise($role->permissions ?? [])
            : [];
    }

    public function userCan(?User $user, string $permission): bool
    {
        return in_array($permission, $this->permissionsFor($user), true);
    }

    // ---------------------------------------------------------------------
    // Roles
    // ---------------------------------------------------------------------

    /**
     * Every role a client can assign: built-ins first, then its own roles.
     *
     * @return list<array{id: int|null, slug: string, name: string, description: string|null, system: bool, permissions: list<string>}>
     */
    public function roleOptions(?Client $client): array
    {
        $options = [];

        foreach (config('client_permissions.system_roles', []) as $slug => $role) {
            $options[] = [
                'id' => null,
                'slug' => $slug,
                // Built-in names stay translation keys; the UI runs them
                // through i18next because PHP has no locale files here.
                'name' => $role['label'],
                'description' => null,
                'system' => true,
                'permissions' => $this->systemPermissions($role),
            ];
        }

        if (! $client) {
            return $options;
        }

        foreach ($this->customRoles($client->id) as $role) {
            $options[] = [
                'id' => $role->id,
                'slug' => $role->slug,
                'name' => $role->name,
                'description' => $role->description,
                'system' => false,
                'permissions' => $this->normalise($role->permissions ?? []),
            ];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public function slugsFor(?Client $client): array
    {
        return array_column($this->roleOptions($client), 'slug');
    }

    /**
     * A slug that is unique inside the tenant, including built-in slugs and
     * Arabic role names (which produce an empty `Str::slug()`).
     */
    public function generateSlug(Client $client, string $name): string
    {
        $base = Str::slug($name);
        // `users.client_role` is varchar(32): keep room for a "-99" suffix.
        $base = $base !== '' ? rtrim(substr($base, 0, 29), '-') : 'role';
        // Read fresh: the controller may create several roles in one process
        // (queue jobs, seeders, tests), and a cached list would collide.
        $taken = array_flip(
            ClientRole::where('client_id', $client->id)->pluck('slug')->all()
        );
        foreach (array_keys(config('client_permissions.system_roles', [])) as $systemSlug) {
            $taken[$systemSlug] = true;
        }
        $slug = $base;
        $i = 2;

        while (isset($taken[$slug])) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    // ---------------------------------------------------------------------
    // Route mapping
    // ---------------------------------------------------------------------

    public function isPublicRoute(string $routeName): bool
    {
        return in_array($routeName, config('client_permissions.public_routes', []), true);
    }

    /**
     * The permission a request needs. `null` means the route is self-service,
     * UNMAPPED means no module claims it (the middleware denies it).
     */
    public function requiredPermissionFor(string $routeName, string $method): ?string
    {
        if ($this->isPublicRoute($routeName)) {
            return null;
        }

        $module = $this->moduleForRoute($routeName);

        if ($module === null) {
            return self::UNMAPPED;
        }

        $action = in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true) ? 'view' : 'write';

        if ($action === 'write' && ! $this->moduleAllowsWrite($module)) {
            $action = 'view';
        }

        return $module.'.'.$action;
    }

    // ---------------------------------------------------------------------
    // Catalogue (for the role editor)
    // ---------------------------------------------------------------------

    /**
     * @return array{
     *     areas: array<string, string>,
     *     modules: list<array{key: string, area: string, label: string, write: bool, view: string, edit: string|null}>,
     *     templates: list<array{key: string, label: string, permissions: list<string>}>
     * }
     */
    public function catalogForUi(): array
    {
        $areas = [];
        foreach (config('client_permissions.areas', []) as $key => $label) {
            // Keys, not values: the client translates them with i18next.
            $areas[$key] = $label;
        }

        $modules = [];
        foreach (config('client_permissions.catalog', []) as $key => $module) {
            $modules[] = [
                'key' => $key,
                'area' => $module['area'],
                'label' => 'permissions.modules.'.$key,
                'write' => (bool) $module['write'],
                'admin_only' => (bool) ($module['admin_only'] ?? false),
                'view' => $key.'.view',
                'edit' => $module['write'] ? $key.'.write' : null,
            ];
        }

        $templates = [];
        foreach (config('client_permissions.templates', []) as $key => $template) {
            $templates[] = [
                'key' => $key,
                'label' => $template['label'],
                'permissions' => $this->templatePermissions($template),
            ];
        }

        return ['areas' => $areas, 'modules' => $modules, 'templates' => $templates];
    }

    /**
     * @param  array{permissions?: list<string>, deny?: list<string>}  $template
     * @return list<string>
     */
    public function templatePermissions(array $template): array
    {
        $permissions = $template['permissions'] ?? [];

        if (in_array('*', $permissions, true)) {
            $permissions = $this->allPermissionKeys();
        }

        // Templates seed custom roles only, and an administrator-only module
        // would just be a dead toggle: the controller keeps its own admin guard.
        $permissions = array_values(array_diff($permissions, $this->adminOnlyKeys()));

        return array_values(array_diff($permissions, $template['deny'] ?? []));
    }

    /**
     * Modules the controllers still restrict to client administrators.
     *
     * @return list<string>
     */
    public function adminOnlyKeys(): array
    {
        $keys = [];

        foreach (config('client_permissions.catalog', []) as $module => $definition) {
            if ($definition['admin_only'] ?? false) {
                $keys[] = $module.'.view';
                if ($definition['write'] ?? false) {
                    $keys[] = $module.'.write';
                }
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    public function allPermissionKeys(): array
    {
        if ($this->allKeys !== null) {
            return $this->allKeys;
        }

        $keys = [];

        foreach (config('client_permissions.catalog', []) as $module => $definition) {
            $keys[] = $module.'.view';
            if ($definition['write'] ?? false) {
                $keys[] = $module.'.write';
            }
        }

        return $this->allKeys = $keys;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * @param  array{wildcard?: bool, read_only?: bool, deny?: list<string>}  $role
     * @return list<string>
     */
    private function systemPermissions(array $role): array
    {
        if ($role['wildcard'] ?? false) {
            $permissions = $this->allPermissionKeys();
        } elseif ($role['read_only'] ?? false) {
            $permissions = array_values(array_filter(
                $this->allPermissionKeys(),
                fn (string $key) => str_ends_with($key, '.view'),
            ));
        } else {
            $permissions = [];
        }

        return array_values(array_diff($permissions, $role['deny'] ?? []));
    }

    /**
     * Drop anything that is not in the current catalogue, so a removed module
     * can never keep granting access to a route that has been re-used.
     *
     * @param  array<int, mixed>  $permissions
     * @return list<string>
     */
    private function normalise(array $permissions): array
    {
        $allowed = array_flip($this->allPermissionKeys());

        return array_values(array_filter(
            array_map('strval', $permissions),
            fn (string $key) => isset($allowed[$key]),
        ));
    }

    /**
     * @return array<int, ClientRole>
     */
    private function customRoles(int $clientId): array
    {
        // Deliberately not cached across calls: role edits (and Octane request
        // boundaries) must be visible immediately, and the role list is tiny.
        return ClientRole::where('client_id', $clientId)
            ->orderBy('name')
            ->get()
            ->all();
    }

    private function moduleForRoute(string $routeName): ?string
    {
        foreach ($this->prefixes() as $prefix => $module) {
            if (str_starts_with($routeName, $prefix)) {
                return $module;
            }
        }

        return null;
    }

    private function moduleAllowsWrite(string $module): bool
    {
        return (bool) config('client_permissions.catalog.'.$module.'.write', false);
    }

    /**
     * @return array<string, string>
     */
    private function prefixes(): array
    {
        if ($this->prefixes !== null) {
            return $this->prefixes;
        }

        $prefixes = [];

        foreach (config('client_permissions.catalog', []) as $module => $definition) {
            foreach ($definition['prefixes'] ?? [] as $prefix) {
                $prefixes[$prefix] = $module;
            }
        }

        // Longest prefix first: client.settings.gdpr must beat client.settings.
        uksort($prefixes, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $this->prefixes = $prefixes;
    }
}
