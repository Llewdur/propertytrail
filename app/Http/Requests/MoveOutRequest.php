<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MoveOutRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
