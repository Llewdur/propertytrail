<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrganisationRole;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePropertyAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('organisation_user', 'user_id')->where(
                    'organisation_id',
                    app(CurrentOrganisation::class)->id() ?? 0,
                ),
            ],
            'role' => ['required', Rule::in(OrganisationRole::assignableValues())],
        ];
    }
}
