<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PropertyAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PropertyAssignment> */
final class PropertyAssignmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'role' => 'property_manager',
        ];
    }
}
