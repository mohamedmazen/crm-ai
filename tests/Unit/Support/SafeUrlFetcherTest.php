<?php

namespace Tests\Unit\Support;

use App\Support\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * SSRF guards for user-supplied knowledge-base URLs.
 */
class SafeUrlFetcherTest extends TestCase
{
    #[Test]
    public function it_blocks_loopback_private_and_metadata_addresses(): void
    {
        Http::preventStrayRequests();
        $fetcher = app(SafeUrlFetcher::class);

        $blocked = [
            'http://127.0.0.1/',
            'http://localhost/',
            'http://169.254.169.254/latest/meta-data/',
            'http://10.0.0.5/',
            'http://172.16.0.1/',
            'http://192.168.1.1/',
            'http://100.64.0.1/',
            'http://[::1]/',
            'http://metadata.google.internal/',
        ];

        foreach ($blocked as $url) {
            try {
                $fetcher->get($url);
                $this->fail("Expected {$url} to be blocked.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_rejects_non_http_schemes_and_embedded_credentials(): void
    {
        Http::preventStrayRequests();
        $fetcher = app(SafeUrlFetcher::class);

        foreach (['file:///etc/passwd', 'ftp://example.com/file', 'http://user:pass@example.com/'] as $url) {
            try {
                $fetcher->get($url);
                $this->fail("Expected {$url} to be rejected.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_fetches_a_public_url(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/page' => Http::response('hello', 200),
        ]);

        $this->assertSame('hello', app(SafeUrlFetcher::class)->get('https://example.com/page'));
    }

    #[Test]
    public function it_blocks_a_redirect_to_a_private_address(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/start' => Http::response('', 302, [
                'Location' => 'http://169.254.169.254/latest/meta-data/',
            ]),
        ]);

        $this->expectException(RuntimeException::class);

        app(SafeUrlFetcher::class)->get('https://example.com/start');
    }

    #[Test]
    public function it_reports_not_modified_and_forwards_conditional_headers(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/page' => Http::response('', 304, ['ETag' => '"v1"']),
        ]);

        $result = app(SafeUrlFetcher::class)->fetch(
            'https://example.com/page',
            30,
            ['If-None-Match' => '"v1"'],
        );

        $this->assertTrue($result['not_modified']);
        $this->assertSame(304, $result['status']);
        $this->assertNull($result['body']);
        $this->assertSame('"v1"', $result['etag']);

        Http::assertSent(fn ($request) => $request->hasHeader('If-None-Match', '"v1"'));
    }
}
