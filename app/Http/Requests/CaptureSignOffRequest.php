<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CaptureSignOffRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'signer_name' => ['required', 'string', 'max:120'],
            'signature' => ['required', 'string'],
            'content_hash' => ['required', 'string', 'size:64'],
            'declaration_version' => ['required', 'string', 'max:32'],
            'confirm_identity' => ['accepted'],
            'confirm_accuracy' => ['accepted'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'selfie' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif',
                'max:10240',
            ],
            'selfie_taken_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
