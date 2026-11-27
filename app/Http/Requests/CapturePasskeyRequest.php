<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A WebAuthn credential the phone returned, in the browser's JSON form. */
final class CapturePasskeyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string', 'max:1024'],
            'credential.rawId' => ['required', 'string', 'max:1024'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
        ];
    }

    /** @return array<array-key, mixed> */
    public function credential(): array
    {
        /** @var array<array-key, mixed> */
        return $this->validated('credential');
    }
}
