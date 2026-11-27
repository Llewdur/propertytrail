<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CaptureSelfieRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif',
                'max:10240',
            ],
            'taken_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
