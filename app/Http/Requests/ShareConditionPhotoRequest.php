<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ShareConditionPhotoRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'evidence_link_id' => ['required', 'integer'],
            // Staff's neutral words; the earlier tenant's own notes are never copied.
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            // A cropped or blurred copy staff made, used in place of the original's pixels.
            'file' => ['sometimes', 'nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240'],
        ];
    }
}
