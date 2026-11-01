<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Super-admin view over the database backups written by `db:backup`.
 *
 * Backups live on a storage disk (default: local) under `backups/`; this
 * controller lists them, triggers a fresh dump, and lets an operator download
 * or delete an archive. The database name never leaves the server: the UI only
 * ever sees file names.
 */
class BackupController extends Controller
{
    public function index(): Response
    {
        $disk = $this->disk();
        $backups = [];
        $error = null;

        try {
            foreach (Storage::disk($disk)->files('backups') as $path) {
                if (! preg_match('/\.sql(\.gz)?$/i', $path)) {
                    continue;
                }

                $backups[] = [
                    'name' => basename($path),
                    'size' => Storage::disk($disk)->size($path),
                    'created_at' => date('c', Storage::disk($disk)->lastModified($path)),
                ];
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        usort($backups, fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return Inertia::render('Admin/Backups/Index', [
            'backups' => $backups,
            'disk' => $disk,
            'databaseDriver' => config('database.default'),
            'canCreate' => config('database.default') === 'mysql',
            'error' => $error,
        ]);
    }

    public function create(): RedirectResponse
    {
        if (config('database.default') !== 'mysql') {
            return back()->with('error', __('Backups are only supported for MySQL databases.'));
        }

        try {
            $exitCode = Artisan::call('db:backup', ['--disk' => $this->disk()]);

            if ($exitCode !== 0) {
                return back()->with('error', __('The backup command failed. Check the server logs.'));
            }

            return back()->with('success', __('Backup created.'));
        } catch (Throwable $e) {
            return back()->with('error', __('Backup failed: :error', ['error' => $e->getMessage()]));
        }
    }

    public function download(string $file): StreamedResponse
    {
        $path = $this->path($file);

        abort_unless(Storage::disk($this->disk())->exists($path), 404);

        return Storage::disk($this->disk())->download($path, basename($path));
    }

    public function destroy(string $file): RedirectResponse
    {
        $path = $this->path($file);

        abort_unless(Storage::disk($this->disk())->exists($path), 404);

        Storage::disk($this->disk())->delete($path);

        return back()->with('success', __('Backup deleted.'));
    }

    /**
     * Confirm an archive is still readable before an operator relies on it.
     * A gzip dump is streamed through zlib; a plain dump only has to be
     * non-empty. Remote disks that cannot expose a local path are reported as
     * unverifiable rather than falsely "valid".
     */
    public function verify(string $file): JsonResponse
    {
        $path = $this->path($file);
        $disk = Storage::disk($this->disk());

        if (! $disk->exists($path)) {
            return response()->json(['valid' => false, 'message' => __('Backup not found.')], 404);
        }

        try {
            $size = (int) $disk->size($path);

            if ($size === 0) {
                return response()->json(['valid' => false, 'message' => __('The backup file is empty.')]);
            }

            if (str_ends_with(strtolower($path), '.gz')) {
                $absolute = $disk->path($path);

                // zlib transparently reads non-gzip input, so check the magic
                // bytes before trusting gzopen.
                $raw = @fopen($absolute, 'rb');
                $magic = $raw ? fread($raw, 2) : false;
                if ($raw) {
                    fclose($raw);
                }

                if ($magic !== "\x1f\x8b") {
                    return response()->json(['valid' => false, 'message' => __('The file is not a valid gzip archive.')]);
                }

                $handle = @gzopen($absolute, 'rb');

                if (! $handle) {
                    return response()->json(['valid' => false, 'message' => __('The gzip archive could not be opened.')]);
                }

                $chunk = gzread($handle, 1024);
                gzclose($handle);

                if ($chunk === false || $chunk === '') {
                    return response()->json(['valid' => false, 'message' => __('The gzip archive is corrupted.')]);
                }
            }

            return response()->json([
                'valid' => true,
                'size' => $size,
                'message' => __('Archive is readable.'),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'valid' => false,
                'message' => __('Verification is not available for this storage disk.'),
            ]);
        }
    }

    private function disk(): string
    {
        return (string) SystemSetting::get('backup.disk', 'local');
    }

    /**
     * Resolve a user-supplied file name inside the backups directory, rejecting
     * path traversal and anything that is not a SQL dump.
     */
    private function path(string $file): string
    {
        $name = basename($file);

        abort_unless($name === $file && preg_match('/^[A-Za-z0-9_.-]+\.sql(\.gz)?$/', $name), 404);

        return 'backups/'.$name;
    }
}
