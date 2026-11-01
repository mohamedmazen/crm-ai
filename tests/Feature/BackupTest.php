<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        SystemSetting::set('backup.disk', 'local');
    }

    #[Test]
    public function the_admin_can_list_backups(): void
    {
        Storage::disk('local')->put('backups/wm_2026_01_01_000000.sql.gz', 'dump');

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->get(route('admin.backups.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Backups/Index')
                ->has('backups', 1)
                ->where('backups.0.name', 'wm_2026_01_01_000000.sql.gz')
                ->where('disk', 'local')
            );
    }

    #[Test]
    public function a_backup_can_be_downloaded(): void
    {
        Storage::disk('local')->put('backups/wm_2026_01_01_000000.sql.gz', 'dump');

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->get(route('admin.backups.download', 'wm_2026_01_01_000000.sql.gz'))
            ->assertOk()
            ->assertDownload('wm_2026_01_01_000000.sql.gz');
    }

    #[Test]
    public function a_backup_can_be_deleted(): void
    {
        Storage::disk('local')->put('backups/wm_2026_01_01_000000.sql.gz', 'dump');

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->delete(route('admin.backups.destroy', 'wm_2026_01_01_000000.sql.gz'))
            ->assertRedirect();

        Storage::disk('local')->assertMissing('backups/wm_2026_01_01_000000.sql.gz');
    }

    #[Test]
    public function a_non_backup_file_cannot_be_downloaded(): void
    {
        Storage::disk('local')->put('backups/notes.txt', 'secret');

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->get(route('admin.backups.download', 'notes.txt'))
            ->assertNotFound();
    }

    #[Test]
    public function the_admin_can_trigger_a_backup(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('db:backup', ['--disk' => 'local'])
            ->andReturn(0);

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->post(route('admin.backups.create'))
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    #[Test]
    public function a_valid_archive_passes_verification(): void
    {
        Storage::disk('local')->put('backups/wm.sql.gz', gzencode('CREATE TABLE t (id int);'));

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->getJson(route('admin.backups.verify', 'wm.sql.gz'))
            ->assertOk()
            ->assertJson(['valid' => true]);
    }

    #[Test]
    public function a_corrupt_archive_fails_verification(): void
    {
        Storage::disk('local')->put('backups/broken.sql.gz', 'not gzip');

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->getJson(route('admin.backups.verify', 'broken.sql.gz'))
            ->assertOk()
            ->assertJson(['valid' => false]);
    }

    #[Test]
    public function a_missing_archive_cannot_be_verified(): void
    {
        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->getJson(route('admin.backups.verify', 'missing.sql.gz'))
            ->assertNotFound();
    }

    #[Test]
    public function an_admin_without_the_permission_cannot_open_backups(): void
    {
        $admin = $this->createSuperAdmin();
        $admin->roles()->first()?->permissions()->detach();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.backups.index'))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error');
    }
}
