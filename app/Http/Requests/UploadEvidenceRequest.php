<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UploadEvidenceRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240'],
            'attachable_type' => ['required', 'string', 'in:property,room,lease,inspection_item'],
            'attachable_id' => ['required', 'integer'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phase' => ['required_if:attachable_type,lease', 'nullable', 'string', 'in:incoming,outgoing,interim'],
            'area_room_id' => ['sometimes', 'nullable', 'integer', 'exists:areas_rooms,id'],
            'taken_at' => ['sometimes', 'nullable', 'date'],
            'replaces_link_id' => ['sometimes', 'nullable', 'integer', 'exists:evidence_links,id'],
        ];
    }
}
