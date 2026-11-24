<?php

declare(strict_types=1);

namespace App\Factories;

use App\Models\Property;

final class PropertyWriteFactory
{
    /** @param array<string, mixed> $data */
    public function create(array $data): Property
    {
        return Property::query()->create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(Property $property, array $data): Property
    {
        $property->fill($data)->save();

        return $property;
    }

    public function delete(Property $property): void
    {
        $property->delete();
    }
}
