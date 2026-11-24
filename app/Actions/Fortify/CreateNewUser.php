<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Factories\OrganisationWriteFactory;
use App\Factories\UserWriteFactory;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Zekini\Auth\Enums\TermsAcceptanceSource;

class CreateNewUser implements CreatesNewUsers
{
    public function __construct(
        private readonly UserWriteFactory $users,
        private readonly OrganisationWriteFactory $organisations,
    ) {}

    /** @param array<string, mixed> $input */
    public function create(array $input): User
    {
        $request = request();
        $organisationName = trim((string) $request->input('organisation_name', ''));
        $currency = (string) $request->input('currency', 'ZAR');
        $timezone = (string) $request->input('timezone', 'Africa/Johannesburg');

        Validator::make([
            ...$input,
            'organisation_name' => $organisationName,
            'currency' => $currency,
            'timezone' => $timezone,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', Password::default()],
            'organisation_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'timezone' => ['sometimes', 'string', 'max:255'],
        ])->validate();

        $organisation = $organisationName === ''
            ? null
            : $this->organisations->create([
                'name' => $organisationName,
                'currency' => $currency,
                'timezone' => $timezone,
            ]);

        $user = $this->users->create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        if ($organisation !== null) {
            $this->users->attachToOrganisation($user, $organisation, 'admin');
        }

        if ($request->boolean('accept_terms')) {
            $this->users->acceptTerms($user, TermsAcceptanceSource::Register);
        }

        return $user;
    }
}
