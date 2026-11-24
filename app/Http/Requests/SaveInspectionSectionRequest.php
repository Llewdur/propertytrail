<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\ConditionVocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveInspectionSectionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['sometimes', 'nullable', 'integer'],
            'items.*.label' => ['required', 'string', 'max:255'],
            'items.*.condition' => ['sometimes', 'nullable', 'string', Rule::in(ConditionVocabulary::keys())],
            'items.*.note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items.*.sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
