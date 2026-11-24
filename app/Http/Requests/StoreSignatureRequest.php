<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\InspectionSignature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSignatureRequest extends FormRequest
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
            'signer_name' => ['required', 'string', 'max:120'],
            'signature' => ['required', 'string'],
        ];
    }
}
