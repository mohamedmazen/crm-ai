<?php

/*
|--------------------------------------------------------------------------
| Trusted Proxies
|--------------------------------------------------------------------------
|
| A comma-separated list of proxy IPs / CIDR ranges that are allowed to set
| the X-Forwarded-* headers (e.g. "127.0.0.1" or "10.0.0.0/8").
|
| Leave empty to trust no proxy: the client IP then comes from REMOTE_ADDR and
| X-Forwarded-For is ignored. Never use "*" — it lets any caller claim any IP,
| which defeats the login rate limiters and the admin IP allowlist.
|
| This is read by Illuminate\Http\Middleware\TrustProxies at request time
| (bootstrap/app.php cannot read env() because the bootstrappers have not run).
|
*/

$proxies = env('TRUSTED_PROXIES');

if (in_array($proxies, [null, '', 'null', '(null)', '*'], true)) {
    $proxies = null;
} else {
    $proxies = array_values(array_filter(array_map('trim', explode(',', (string) $proxies))));
    $proxies = $proxies === [] ? null : $proxies;
}

return [
    'proxies' => $proxies,
];
