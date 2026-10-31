<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\SecureHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The CSP must allow the Vite dev server in development. Herd serves Vite from
 * the site host over HTTPS (https://crm.test:5173), not just localhost, which is
 * what these tests protect against regressing.
 */
class SecureHeadersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function csp_allows_the_request_host_on_the_vite_port(): void
    {
        config(['app.env' => 'local']);

        $csp = (string) $this->get('https://crm.test/login')
            ->headers->get('Content-Security-Policy');

        // Vite shares the app's hostname on its own port under Herd, so the
        // dev-server origin must be allow-listed for scripts, styles and HMR.
        $this->assertStringContainsString('https://crm.test:5173', $csp);
        $this->assertStringContainsString('script-src-elem', $csp);
        $this->assertStringContainsString('style-src-elem', $csp);
        $this->assertStringContainsString('connect-src', $csp);

        // The dev origin must stay a separate token from the WebSocket sources;
        // a missing separator yields an invalid "http://crm.test:5173ws:" source
        // that silently drops "ws:" and blocks the dev WebSocket connection.
        $this->assertStringNotContainsString('5173ws:', $csp);
        $this->assertStringContainsString(' ws: wss:', $csp);
    }

    #[Test]
    public function production_csp_does_not_allow_a_vite_dev_server(): void
    {
        config(['app.env' => 'production']);

        $csp = (string) $this->get('https://crm.test/login')
            ->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString(':5173', $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
    }

    #[Test]
    public function the_hot_file_url_is_normalised_to_a_csp_origin(): void
    {
        $this->assertSame('https://crm.test:5173', SecureHeaders::devServerOrigin("https://crm.test:5173\n"));
        $this->assertSame('http://localhost:5173', SecureHeaders::devServerOrigin('http://localhost:5173'));
        $this->assertSame('https://crm.test', SecureHeaders::devServerOrigin('https://crm.test/'));
        $this->assertNull(SecureHeaders::devServerOrigin('http://[::1]:5173'));
        $this->assertNull(SecureHeaders::devServerOrigin('not a url'));
    }
}
