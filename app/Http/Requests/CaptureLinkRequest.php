<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CaptureLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CaptureLinkRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phase' => ['required', 'string', Rule::in(CaptureLink::PHASES)],
        ];
    }
}
