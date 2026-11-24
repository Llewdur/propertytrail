<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\InspectionSignature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InspectionContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'party' => ['required', 'string', Rule::in(InspectionSignature::PARTIES)],
            'email' => ['nullable', 'email:rfc', 'max:254', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[1-9]\d{7,14}$/', 'required_without:email'],
        ];
    }
}
