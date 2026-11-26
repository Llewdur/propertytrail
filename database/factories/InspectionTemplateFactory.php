<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InspectionTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InspectionTemplate> */
final class InspectionTemplateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'room_type' => 'kitchen',
            'name' => fake()->words(3, true),
            'is_active' => true,
        ];
    }
}
