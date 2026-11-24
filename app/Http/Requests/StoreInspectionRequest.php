<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Inspection;
use Illuminate\Foundation\Http\FormRequest;

final class StoreInspectionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'property_id' => ['required', 'integer'],
            'phase' => ['required', 'string', 'in:' . implode(',', Inspection::PHASES)],
            'lease_id' => ['sometimes', 'nullable', 'integer'],
            'overall_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
