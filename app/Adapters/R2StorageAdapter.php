<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\Adapters\StorageAdapterInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

final class R2StorageAdapter implements StorageAdapterInterface
{
    public function put(string $path, string $contents): void
    {
        if ($this->disk()->put($path, $contents) === false) {
            throw new \RuntimeException('Storage write failed for ' . $path);
        }
    }

    public function get(string $path): ?string
    {
        return $this->disk()->get($path);
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function delete(string $path): void
    {
        $this->disk()->delete($path);
    }

    /**
     * Resolved on use, not on construction: many controllers depend on this adapter without touching a
     * file, and building the client throws while object storage is not configured.
     */
    private function disk(): Filesystem
    {
        return Storage::disk('s3');
    }
}
