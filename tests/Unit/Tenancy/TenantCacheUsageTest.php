<?php

namespace Tests\Unit\Tenancy;

use App\Support\Cache\TenantCache;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the tenant cache boundary.
 *
 * Tenant data must go through {@see TenantCache} so every key
 * carries a workspace prefix. The entries below are the only places allowed to
 * touch the global `Cache` facade directly, and each one is platform-wide or
 * keyed by a globally unique id — never by tenant-scoped data.
 */
class TenantCacheUsageTest extends TestCase
{
    /**
     * App-relative paths allowed to call the global Cache facade.
     *
     * @var list<string>
     */
    private const SYSTEM_CACHE_FILES = [
        // Platform-wide settings: identical for every workspace.
        'Providers/BrandingServiceProvider.php',
        'Models/SystemSetting.php',
        'Models/Translation.php',
        'Services/StorageManager.php',
        'Services/License/LicenseManager.php',
        'Services/I18n/I18nFileService.php',
        'Http/Controllers/Admin/CronSetupController.php',

        // Dedup guards and locks keyed by globally unique record ids.
        'Listeners/SendNewMessageNotification.php',
        'Listeners/SendCampaignCompletedNotification.php',
        'Listeners/SendAutomationFailedNotification.php',
        'Listeners/RecordBehavioralScore.php',
        'Listeners/AutoReplyListener.php',
        'Listeners/AutomationTriggerListener.php',
        'Modules/Automation/Services/AutomationEngine.php',

        // Keys derived from globally unique values (domains, provider credentials).
        'Modules/Broadcasting/Services/DomainVerifier.php',
        'Modules/Broadcasting/Services/Sms/CequensDriver.php',
        'Modules/Sales/Services/Calls/CequensCallProvider.php',

        // The tenant wrapper itself.
        'Support/Cache/TenantCache.php',
    ];

    #[Test]
    public function tenant_data_is_only_cached_through_the_tenant_cache(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());

            if (in_array($relative, self::SYSTEM_CACHE_FILES, true)) {
                continue;
            }

            if (preg_match('/(?<![A-Za-z0-9_])Cache::/', $file->getContents()) === 1) {
                $offenders[] = $relative;
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            'Tenant-scoped values must use App\Support\Cache\TenantCache::for($workspaceId); '
            .'the global Cache facade may only be used for platform-wide entries. Offending files: '
            .implode(', ', $offenders),
        );
    }
}
