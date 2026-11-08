<?php

namespace Tests\Feature\Integrations;

use App\Models\SmtpConfiguration;
use App\Modules\AI\Exceptions\AiNotConfiguredException;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\Broadcasting\Models\SmsProviderConfig;
use App\Modules\Broadcasting\Models\WorkspaceSmtpConfig;
use App\Modules\Broadcasting\Services\Sms\SmsDriverInterface;
use App\Modules\Broadcasting\Services\Sms\SmsDriverManager;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Services\Mail\TenantSmtpResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * BYOK-only mode: a tenant must supply its own provider credentials, and the
 * platform must never silently spend the operator's AI / SMS / WhatsApp / SMTP
 * credentials on tenant traffic. Platform-owned services (Meta App identity,
 * storage, payments) keep resolving normally.
 */
class ByokOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function systemConfig(string $provider, array $credentials): IntegrationConfig
    {
        return IntegrationConfig::create([
            'provider' => $provider,
            'label' => $provider,
            'mode' => 'live',
            'enabled' => true,
            'credentials' => $credentials,
        ]);
    }

    private function enableByok(): void
    {
        config(['integrations.byok_only' => true]);
    }

    public function test_llm_system_default_is_not_used_when_byok_only_is_enabled(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $this->systemConfig('llm_openai_default', ['api_key' => 'sk-system']);

        $this->enableByok();

        $this->assertNull(CredentialResolver::for($workspace)->llm('openai'));
    }

    public function test_llm_system_default_still_resolves_when_byok_only_is_off(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $this->systemConfig('llm_openai_default', ['api_key' => 'sk-system']);

        config(['integrations.byok_only' => false]);

        $creds = CredentialResolver::for($workspace)->llm('openai');

        $this->assertNotNull($creds);
        $this->assertSame('sk-system', $creds->apiKey());
    }

    public function test_workspace_llm_key_still_resolves_when_byok_only_is_enabled(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $this->systemConfig('llm_openai_default', ['api_key' => 'sk-system']);

        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-workspace'],
            'enabled' => true,
        ]);

        $this->enableByok();

        $creds = CredentialResolver::for($workspace)->llm('openai');

        $this->assertNotNull($creds);
        $this->assertSame('sk-workspace', $creds->apiKey());
    }

    public function test_llm_manager_fails_loudly_without_a_workspace_key_in_byok_only_mode(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $this->systemConfig('llm_openai_default', ['api_key' => 'sk-system']);

        $this->enableByok();

        $this->expectException(AiNotConfiguredException::class);

        LlmManager::forWorkspace((int) $workspace->id);
    }

    public function test_sms_system_default_is_not_used_when_byok_only_is_enabled(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $this->systemConfig('sms_twilio_default', [
            'account_sid' => 'AC-system',
            'auth_token' => 'system-token',
            'from_number' => '+10000000000',
        ]);

        $this->enableByok();

        $this->assertNull(CredentialResolver::for($workspace)->sms('twilio'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SMS Gateways');

        SmsDriverManager::forWorkspace((int) $workspace->id);
    }

    public function test_workspace_sms_config_still_works_when_byok_only_is_enabled(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $this->systemConfig('sms_twilio_default', [
            'account_sid' => 'AC-system',
            'auth_token' => 'system-token',
            'from_number' => '+10000000000',
        ]);

        SmsProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'twilio',
            'credentials' => [
                'account_sid' => 'AC-workspace',
                'auth_token' => 'workspace-token',
                'from_number' => '+20000000000',
            ],
            'default' => true,
        ]);

        $this->enableByok();

        $driver = SmsDriverManager::forWorkspace((int) $workspace->id);

        $this->assertInstanceOf(SmsDriverInterface::class, $driver);
    }

    public function test_whatsapp_system_user_token_is_not_a_fallback_when_byok_only_is_enabled(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $this->systemConfig('meta_app', [
            'app_id' => 'app-id',
            'app_secret' => 'app-secret',
            'system_user_token' => 'system-waba-token',
        ]);

        config(['integrations.byok_only' => false]);
        $this->assertSame(
            'system-waba-token',
            WhatsappBusinessAccount::resolveAccessTokenForWorkspace((int) $workspace->id),
        );

        $this->enableByok();
        $this->assertNull(
            WhatsappBusinessAccount::resolveAccessTokenForWorkspace((int) $workspace->id),
        );
    }

    public function test_workspace_waba_token_still_resolves_when_byok_only_is_enabled(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $this->systemConfig('meta_app', [
            'app_id' => 'app-id',
            'app_secret' => 'app-secret',
            'system_user_token' => 'system-waba-token',
        ]);

        WhatsappBusinessAccount::create([
            'workspace_id' => $workspace->id,
            'waba_id' => 'waba-123',
            'credentials' => ['system_user_token' => 'workspace-waba-token'],
            'status' => 'active',
        ]);

        $this->enableByok();

        $this->assertSame(
            'workspace-waba-token',
            WhatsappBusinessAccount::resolveAccessTokenForWorkspace((int) $workspace->id),
        );
    }

    public function test_meta_app_identity_still_resolves_in_byok_only_mode(): void
    {
        $this->systemConfig('meta_app', [
            'app_id' => 'app-id',
            'app_secret' => 'app-secret',
            'system_user_token' => 'system-waba-token',
        ]);

        $this->enableByok();

        $meta = CredentialResolver::system()->meta();

        $this->assertNotNull($meta);
        $this->assertSame('app-id', $meta->appId());
        $this->assertSame('app-secret', $meta->appSecret());
    }

    public function test_tenant_email_does_not_fall_back_to_the_platform_mailer_in_byok_only_mode(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];

        SmtpConfiguration::create([
            'host' => 'smtp.platform.test',
            'port' => 587,
            'username' => 'platform',
            'password' => 'secret',
            'encryption' => 'tls',
            'from_email' => 'noreply@platform.test',
            'from_name' => 'Platform',
            'is_active' => true,
        ]);

        config(['integrations.byok_only' => false]);
        $this->assertInstanceOf(SmtpConfiguration::class, TenantSmtpResolver::resolve((int) $workspace->id));

        $this->enableByok();
        $this->assertNull(TenantSmtpResolver::resolve((int) $workspace->id));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Email Server');

        TenantSmtpResolver::resolveOrFail((int) $workspace->id);
    }

    public function test_workspace_smtp_is_used_in_byok_only_mode(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];

        SmtpConfiguration::create([
            'host' => 'smtp.platform.test',
            'port' => 587,
            'username' => 'platform',
            'password' => 'secret',
            'encryption' => 'tls',
            'from_email' => 'noreply@platform.test',
            'from_name' => 'Platform',
            'is_active' => true,
        ]);

        WorkspaceSmtpConfig::create([
            'workspace_id' => $workspace->id,
            'host' => 'smtp.tenant.test',
            'port' => 587,
            'username' => 'tenant',
            'password' => 'secret',
            'encryption' => 'tls',
            'from_email' => 'noreply@tenant.test',
            'from_name' => 'Tenant',
            'is_active' => true,
        ]);

        $this->enableByok();

        $smtp = TenantSmtpResolver::resolveOrFail((int) $workspace->id);

        $this->assertInstanceOf(WorkspaceSmtpConfig::class, $smtp);
        $this->assertSame('smtp.tenant.test', $smtp->host);
    }
}
