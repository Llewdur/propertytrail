<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InspectionTemplate;
use App\Models\InspectionTemplateItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InspectionTemplateItem> */
final class InspectionTemplateItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'inspection_template_id' => InspectionTemplate::factory(),
            'label' => fake()->words(2, true),
            'requires_note' => false,
            'sort_order' => 0,
        ];
    }
}
