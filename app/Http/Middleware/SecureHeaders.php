<?php

namespace App\Http\Middleware;

use App\Modules\Integrations\Services\CredentialResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SecureHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Per-request CSP nonce. It is applied to Vite/Ziggy tags and the inline
        // scripts in app.blade.php, so script-src can stop trusting every
        // inline <script> ('unsafe-inline' remains only as a legacy fallback).
        $nonce = Str::random(40);
        $request->attributes->set('csp_nonce', $nonce);
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // Prevent the browser from caching authenticated pages. Without this, the
        // back button after logout restores a cached/bfcache copy of the dashboard,
        // making it look as if the session is still active. `no-store` forces a
        // server round-trip on back/forward, which redirects to login once logged out.
        if (auth()->guard('web')->check() || auth()->guard('admin')->check()) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        $csp = $this->buildCsp($request, $nonce);
        if ($csp !== null) {
            $response->headers->set('Content-Security-Policy', $csp);
        }

        return $response;
    }

    private function buildCsp(Request $request, string $nonce): ?string
    {
        // iyzico's subscription checkout is embedded markup that pulls its own scripts and
        // 3-D Secure frames from iyzipay.com. Widen the policy only on the page that renders
        // that form rather than for the whole app.
        $iyzico = $request->routeIs('checkout.iyzico.form') ? ' https://iyzipay.com https://*.iyzipay.com' : '';

        // Public forms may embed Turnstile or reCAPTCHA; allow their scripts,
        // challenge frames and verification calls only on the form page.
        $captcha = $this->captchaSources($request);

        $unsafeEval = config('app.env') !== 'production' ? " 'unsafe-eval'" : '';
        $vite = $this->viteDevSources($request);

        // The nonce is enforced only in production. Locally, Herd Studio injects
        // its own inline bootstrap and `/herd-studio/loader.js` after our
        // response is built, so it cannot carry the nonce; keeping the legacy
        // policy there avoids breaking the development overlay. iyzico renders
        // third-party checkout markup (with its own inline scripts) that we
        // cannot nonce either, so that page keeps the legacy policy too.
        $nonceSource = config('app.env') === 'production' && ! $request->routeIs('checkout.iyzico.form')
            ? " 'nonce-{$nonce}' 'strict-dynamic'"
            : '';

        $scriptSrc = "'self' 'unsafe-inline'".$nonceSource.$unsafeEval.$vite.$this->thirdPartyScriptSources().$iyzico.$captcha;
        $styleSrc = "'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com".$vite.$this->thirdPartyStyleSources().$iyzico.$captcha;
        $fontSrc = "'self' data: https://fonts.bunny.net https://fonts.gstatic.com https://fonts.googleapis.com".$iyzico;

        $frameSrc = "'self'".$this->metaFrameSources().$iyzico.$captcha;

        $directives = array_filter([
            "default-src 'self'",
            'script-src '.$scriptSrc,
            'script-src-elem '.$scriptSrc,
            'style-src '.$styleSrc,
            'style-src-elem '.$styleSrc,
            "img-src 'self' data: https: blob:",
            'font-src '.$fontSrc,
            "connect-src 'self'".$vite.$this->connectSources().$iyzico.$captcha,
            'frame-src '.$frameSrc,
            "frame-ancestors 'self'",
        ]);

        return implode('; ', $directives);
    }

    /**
     * CSP hosts for the CAPTCHA widget, or an empty string when the page is
     * not the public form or no provider is configured.
     */
    private function captchaSources(Request $request): string
    {
        if (! $request->routeIs('forms.public.show')) {
            return '';
        }

        $configured = filled(config('services.turnstile.site_key'))
            || filled(config('services.recaptcha.site_key'));

        if (! $configured) {
            return '';
        }

        return ' https://challenges.cloudflare.com https://www.google.com https://www.gstatic.com https://recaptcha.google.com';
    }

    /**
     * Allow the Vite dev server in development so CSP does not block its
     * scripts/styles. The dev origin is not always localhost: Herd serves Vite
     * from the site host over HTTPS (e.g. https://crm.test:5173), which is what
     * Laravel writes to public/hot.
     */
    private function viteDevSources(Request $request): string
    {
        if (config('app.env') === 'production') {
            return '';
        }

        // Vite default port 5173. Do not use http://[::1]:5173 — invalid in
        // script-src for Chromium and triggers console noise.
        $sources = ['http://localhost:5173', 'http://127.0.0.1:5173'];

        // Authoritative origin: whatever the Laravel Vite plugin published.
        if (is_file($hot = public_path('hot'))) {
            $origin = self::devServerOrigin((string) file_get_contents($hot));
            if ($origin !== null) {
                $sources[] = $origin;
            }
        }

        // Fallback for a stale/missing hot file: Vite shares the app's hostname,
        // just on its own port, so allow the request host in both schemes.
        $host = $request->getHost();
        if (str_contains($host, '.') || $host === 'localhost') {
            $sources[] = 'http://'.$host.':5173';
            $sources[] = 'https://'.$host.':5173';
        }

        return ' '.implode(' ', array_unique($sources));
    }

    /**
     * Normalise a dev-server URL to a CSP source. IPv6 literals are dropped
     * because Chromium rejects them in script-src.
     */
    public static function devServerOrigin(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || empty($parts['host']) || str_contains($parts['host'], ':')) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return ($parts['scheme'] ?? 'http').'://'.$parts['host'].$port;
    }

    /** OneSignal (when configured), Meta JS SDK, and Cloudflare Web Analytics / beacon scripts. */
    private function thirdPartyScriptSources(): string
    {
        $extra = ' https://static.cloudflareinsights.com';
        if (filled(config('services.onesignal.app_id'))) {
            // SDK loads from cdn; runtime sync/scripts also come from api.* (see OneSignal v16 CSP docs).
            $extra .= ' https://cdn.onesignal.com https://*.onesignal.com';
        }
        if ($this->metaSdkEnabled()) {
            $extra .= ' https://connect.facebook.net';
        }

        return $extra;
    }

    /** OneSignal injects styles from the apex host (e.g. OneSignalSDK.page.styles.css). */
    private function thirdPartyStyleSources(): string
    {
        if (! filled(config('services.onesignal.app_id'))) {
            return '';
        }

        return ' https://onesignal.com https://*.onesignal.com';
    }

    private function connectSources(): string
    {
        $sources = [];
        if (config('app.env') !== 'production') {
            $sources[] = 'ws:';
            $sources[] = 'wss:';
        }
        $url = config('app.url');
        if ($url) {
            $sources[] = parse_url($url, PHP_URL_HOST) ?: $url;
        }
        if (filled(config('services.onesignal.app_id'))) {
            $sources[] = 'https://onesignal.com';
            $sources[] = 'https://*.onesignal.com';
        }
        if ($this->metaSdkEnabled()) {
            $sources[] = 'https://graph.facebook.com';
            $sources[] = 'https://www.facebook.com';
            $sources[] = 'https://web.facebook.com';
            $sources[] = 'https://business.facebook.com';
            // FB JS SDK fetches /app_config/json/{appId}/ from here during init. Without it the
            // Embedded Signup dialog config never loads and Meta never posts WA_EMBEDDED_SIGNUP.
            $sources[] = 'https://connect.facebook.net';
        }

        return $sources === [] ? '' : ' '.implode(' ', array_unique($sources));
    }

    /** Allow Meta Login / Embedded Signup dialogs in iframes when the Meta App is configured. */
    private function metaFrameSources(): string
    {
        if (! $this->metaSdkEnabled()) {
            return '';
        }

        return ' https://www.facebook.com https://web.facebook.com https://business.facebook.com https://connect.facebook.net';
    }

    private function metaSdkEnabled(): bool
    {
        try {
            return filled(CredentialResolver::system()->meta()?->appId());
        } catch (\Throwable) {
            return false;
        }
    }
}
