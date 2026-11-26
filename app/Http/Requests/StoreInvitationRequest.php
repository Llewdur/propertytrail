<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrganisationRole;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email',
                'max:255',
                // Only a pending invitation blocks another one for the same address.
                Rule::unique('organisation_invitations', 'email')->where(static fn(Builder $query) => $query
                    ->where('organisation_id', app(CurrentOrganisation::class)->id() ?? 0)
                    ->whereNull('accepted_at')
                    ->where('expires_at', '>', now())),
            ],
            'role' => ['required', Rule::in(OrganisationRole::assignableValues())],
        ];
    }
}
