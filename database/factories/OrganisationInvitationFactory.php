<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OrganisationInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganisationInvitation> */
final class OrganisationInvitationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'role' => 'viewer',
            'token_hash' => hash('sha256', fake()->unique()->uuid()),
            'expires_at' => now()->addDays(7),
        ];
    }
}
