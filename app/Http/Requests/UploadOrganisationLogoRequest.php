<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UploadOrganisationLogoRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'logo' => ['required', 'file', 'mimetypes:image/png,image/jpeg', 'max:1024'],
        ];
    }
}
