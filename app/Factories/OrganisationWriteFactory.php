<?php

declare(strict_types=1);

namespace App\Factories;

use App\Models\Organisation;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class OrganisationWriteFactory
{
    /** @param array<string, mixed> $data */
    public function create(array $data): Organisation
    {
        return Organisation::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'name' => $data['name'],
            'slug' => Str::slug($data['name']) . '-' . Str::lower(Str::random(6)),
            'status' => 'active',
            'currency' => $data['currency'] ?? 'ZAR',
            'timezone' => $data['timezone'] ?? 'Africa/Johannesburg',
            'settings' => $data['settings'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(Organisation $organisation, array $data): Organisation
    {
        $organisation->fill($data)->save();

        return $organisation;
    }
}
