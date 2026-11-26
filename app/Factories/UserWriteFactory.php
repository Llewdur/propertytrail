<?php

declare(strict_types=1);

namespace App\Factories;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Ramsey\Uuid\Uuid;
use Zekini\Auth\Enums\TermsAcceptanceSource;
use Zekini\Auth\Support\TermsAcceptanceService;

final class UserWriteFactory
{
    public function __construct(
        private readonly TermsAcceptanceService $terms,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): User
    {
        return User::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
    }

    public function attachToOrganisation(User $user, Organisation $organisation, string $role = 'admin'): void
    {
        if (!$user->organisations()->whereKey($organisation->getKey())->exists()) {
            $user->organisations()->attach($organisation, ['role' => $role]);
        }
    }

    /** Joins the user to the organisation, or changes the role of an existing member. */
    public function setRole(User $user, Organisation $organisation, string $role): void
    {
        $user->organisations()->syncWithoutDetaching([(int) $organisation->getKey() => ['role' => $role]]);
    }

    public function acceptTerms(User $user, TermsAcceptanceSource $source = TermsAcceptanceSource::Register): void
    {
        $this->terms->record($user, request(), $source);
    }

    public function hasAcceptedTerms(User $user): bool
    {
        return $this->terms->hasAccepted($user);
    }
}
