<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CaptureLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SendCaptureLinkRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phase' => ['required', 'string', Rule::in(CaptureLink::PHASES)],
            'email' => ['nullable', 'email:rfc', 'max:254', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[1-9]\d{7,14}$/', 'required_without:email'],
        ];
    }
}
