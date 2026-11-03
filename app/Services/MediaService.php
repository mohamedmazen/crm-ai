<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaService
{
    public function __construct(private StorageManager $storageManager) {}

    public function store(
        UploadedFile $file,
        Model $owner,
        string $collection = 'default',
        ?string $disk = null
    ): Media {
        // Derive the extension from the detected MIME type, never from the
        // client-supplied filename, so an upload cannot be stored as .php/.phtml.
        $ext = $file->guessExtension() ?: 'bin';
        $filename = $file->getClientOriginalName();

        // Resolve disk from StorageManager unless caller explicitly passes one
        $resolvedDisk = $disk ?? $this->storageManager->diskName();
        $rawPath = 'media/'.Str::uuid().'.'.$ext;
        $path = $this->storageManager->prefixedPath($rawPath);

        Storage::disk($resolvedDisk)->putFileAs(dirname($path), $file, basename($path));

        return Media::create([
            'mediable_type' => get_class($owner),
            'mediable_id' => $owner->getKey(),
            'disk' => $resolvedDisk,
            'path' => $path,
            'filename' => $filename,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'collection' => $collection,
        ]);
    }

    /**
     * Get total storage used by owner in bytes.
     */
    public function usedBytes(Model $owner, ?string $collection = null): int
    {
        $query = Media::where('mediable_type', get_class($owner))
            ->where('mediable_id', $owner->getKey());

        if ($collection) {
            $query->where('collection', $collection);
        }

        return (int) $query->sum('size_bytes');
    }

    /**
     * Get storage quota in bytes from the effective plan's `storage` limit,
     * which is stored in megabytes (e.g. 5120 = 5 GB).
     */
    public function quotaBytes(User $user): int
    {
        // Resolve the plan the same way limits/feature gates do: admin-assigned
        // subscriptions take precedence, then self-serve subscriptions.
        $plan = $user->effectivePlan();

        // No plan in effect keeps the old conservative 1 GB floor.
        if (! $plan) {
            return 1024 * 1024 * 1024;
        }

        $storageMb = $plan->limitValue('storage');

        // A null limit means unlimited.
        if ($storageMb === null) {
            return PHP_INT_MAX;
        }

        return (int) $storageMb * 1024 * 1024;
    }
}
