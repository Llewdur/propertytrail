<?php

declare(strict_types=1);

namespace App\Factories;

use App\Models\Site;

final class SiteWriteFactory
{
    /** @param array<string, mixed> $data */
    public function create(array $data): Site
    {
        return Site::query()->create($data);
    }
}
