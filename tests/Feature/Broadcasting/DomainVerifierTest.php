<?php

namespace Tests\Feature\Broadcasting;

use App\Modules\Broadcasting\Models\WorkspaceSmtpConfig;
use App\Modules\Broadcasting\Services\DomainVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DomainVerifierTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_detects_spf_and_dkim_records(): void
    {
        $verifier = new class extends DomainVerifier
        {
            protected function txtRecords(string $name): array
            {
                return match ($name) {
                    'example.com' => ['v=spf1 include:_spf.google.com ~all'],
                    'google._domainkey.example.com' => ['v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQ'],
                    default => [],
                };
            }
        };

        $result = $verifier->check('example.com');

        $this->assertTrue($result['spf']);
        $this->assertTrue($result['dkim']);
        $this->assertSame('google', $result['dkim_selector']);
        $this->assertTrue($result['verified']);
    }

    #[Test]
    public function it_reports_missing_records(): void
    {
        $verifier = new class extends DomainVerifier
        {
            protected function txtRecords(string $name): array
            {
                return [];
            }
        };

        $result = $verifier->check('unconfigured.example');

        $this->assertFalse($result['spf']);
        $this->assertFalse($result['dkim']);
        $this->assertFalse($result['verified']);
        $this->assertNull($result['dkim_selector']);
    }

    #[Test]
    public function it_extracts_the_domain_from_an_email_address(): void
    {
        $this->assertSame('example.com', DomainVerifier::domainFromEmail('Support@Example.com'));
        $this->assertSame('example.com', DomainVerifier::domainFromEmail('example.com'));
    }

    #[Test]
    public function the_verify_endpoint_returns_the_domain_status(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        WorkspaceSmtpConfig::create([
            'workspace_id' => $workspace->id,
            'host' => 'smtp.example.com',
            'port' => 587,
            'username' => 'user',
            'password' => 'secret',
            'encryption' => 'tls',
            'from_email' => 'noreply@example.com',
            'from_name' => 'Example',
            'is_active' => true,
        ]);

        $this->mock(DomainVerifier::class, function ($mock) {
            $mock->shouldReceive('forget')->once()->andReturnNull();
            $mock->shouldReceive('verify')->once()->with('example.com')->andReturn([
                'domain' => 'example.com',
                'spf' => true,
                'dkim' => true,
                'dkim_selector' => 'default',
                'verified' => true,
            ]);
        });

        $this->actingAs($user)
            ->postJson(route('client.email-server.verify-domain'))
            ->assertOk()
            ->assertJson([
                'domain' => 'example.com',
                'verified' => true,
            ]);
    }
}
