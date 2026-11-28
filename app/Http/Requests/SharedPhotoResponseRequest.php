<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\SharedConditionPhoto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SharedPhotoResponseRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'response' => ['required', 'string', Rule::in(SharedConditionPhoto::RESPONSES)],
            'note' => ['required_if:response,disputed', 'nullable', 'string', 'max:1000'],
        ];
    }
}
