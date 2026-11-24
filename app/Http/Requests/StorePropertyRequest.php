<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StorePropertyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'reference_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'property_type' => [
                'required',
                'string',
                'in:residential_long,residential_short,commercial,industrial,land',
            ],
            'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'province' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'gps_latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'gps_longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'status' => ['sometimes', 'string', 'in:active,inactive,archived'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
