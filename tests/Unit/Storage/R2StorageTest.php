<?php

namespace Tests\Unit\Storage;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Services\StorageManager;
use Tests\TestCase;

class R2StorageTest extends TestCase
{
    public function test_storage_s3_with_r2_endpoint_detects_path_style(): void
    {
        $manager = app(StorageManager::class);

        $reflection = new \ReflectionClass($manager);
        $method = $reflection->getMethod('buildDiskConfig');
        $method->setAccessible(true);

        $creds = [
            'key' => 'test-key',
            'secret' => 'test-secret',
            'bucket' => 'crm',
            'url' => 'https://e9ae058f2c5b1f61f7ab5613abf732eb.r2.cloudflarestorage.com',
        ];

        $diskCfg = $method->invoke($manager, 'storage_s3', $creds);

        $this->assertSame('s3', $diskCfg['driver']);
        $this->assertSame('https://e9ae058f2c5b1f61f7ab5613abf732eb.r2.cloudflarestorage.com', $diskCfg['endpoint']);
        $this->assertTrue($diskCfg['use_path_style_endpoint']);
        $this->assertSame('auto', $diskCfg['region']);
        $this->assertNull($diskCfg['url'], 'R2 API endpoint should not be used as public URL');
    }

    public function test_storage_r2_provider_builds_correct_disk_config(): void
    {
        $manager = app(StorageManager::class);

        $reflection = new \ReflectionClass($manager);
        $method = $reflection->getMethod('buildDiskConfig');
        $method->setAccessible(true);

        $creds = [
            'key' => 'test-key',
            'secret' => 'test-secret',
            'bucket' => 'crm',
            'endpoint' => 'https://e9ae058f2c5b1f61f7ab5613abf732eb.r2.cloudflarestorage.com',
            'url' => 'https://pub-187eade37fea405b81497bb2ca221a50.r2.dev',
        ];

        $diskCfg = $method->invoke($manager, 'storage_r2', $creds);

        $this->assertSame('s3', $diskCfg['driver']);
        $this->assertSame('https://e9ae058f2c5b1f61f7ab5613abf732eb.r2.cloudflarestorage.com', $diskCfg['endpoint']);
        $this->assertTrue($diskCfg['use_path_style_endpoint']);
        $this->assertSame('auto', $diskCfg['region']);
        $this->assertSame('https://pub-187eade37fea405b81497bb2ca221a50.r2.dev', $diskCfg['url']);
    }

    public function test_storage_providers_includes_r2(): void
    {
        $this->assertContains('storage_r2', IntegrationConfig::STORAGE_PROVIDERS);
        $this->assertSame('r2', IntegrationConfig::STORAGE_DISK_MAP['storage_r2']);
        $this->assertSame('Cloudflare R2', IntegrationConfig::LABELS['storage_r2']);
    }
}

