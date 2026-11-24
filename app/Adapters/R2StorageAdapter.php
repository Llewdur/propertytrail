<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\Adapters\StorageAdapterInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

final class R2StorageAdapter implements StorageAdapterInterface
{
    private readonly Filesystem $disk;

    public function __construct()
    {
        $this->disk = Storage::disk('s3');
    }

    public function put(string $path, string $contents): void
    {
        if ($this->disk->put($path, $contents) === false) {
            throw new \RuntimeException('Storage write failed for ' . $path);
        }
    }

    public function get(string $path): ?string
    {
        return $this->disk->get($path);
    }

    public function exists(string $path): bool
    {
        return $this->disk->exists($path);
    }

    public function delete(string $path): void
    {
        $this->disk->delete($path);
    }
}
