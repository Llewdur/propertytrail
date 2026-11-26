<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PilotEnquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PilotEnquiry> */
final class PilotEnquiryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'company' => fake()->company(),
            'portfolio_size' => '1-10',
            'message' => fake()->sentence(),
        ];
    }
}
