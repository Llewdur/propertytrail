<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveLeaseAreasRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'area_ids' => ['present', 'array'],
            'area_ids.*' => ['integer', 'distinct'],
        ];
    }
}
