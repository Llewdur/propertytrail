<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Adapters\StorageAdapterInterface;

final class FakeStorageAdapter implements StorageAdapterInterface
{
    /** @var array<string, string> */
    public array $objects = [];

    public function put(string $path, string $contents): void
    {
        $this->objects[$path] = $contents;
    }

    public function get(string $path): ?string
    {
        return $this->objects[$path] ?? null;
    }

    public function exists(string $path): bool
    {
        return array_key_exists($path, $this->objects);
    }

    public function delete(string $path): void
    {
        unset($this->objects[$path]);
    }
}
