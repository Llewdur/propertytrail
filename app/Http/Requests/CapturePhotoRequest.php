<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CapturePhotoRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $roomKey = ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'];

        return [
            'file' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif',
                'max:10240',
            ],
            // Either a room that exists, or the tenant's own key and name for a new one.
            'area_room_id' => ['required_without:room_key', 'nullable', 'integer'],
            'room_key' => $roomKey,
            'room_name' => ['required_with:room_key', 'nullable', 'string', 'max:80'],
            'room_outdoor' => ['sometimes', 'boolean'],
            'adjoins_area_room_id' => ['sometimes', 'nullable', 'integer'],
            'adjoins_room_key' => $roomKey,
            'corner' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:99'],
            'shot' => ['sometimes', 'nullable', 'string', 'in:corner,nearest,extra,closeup'],
            'issue_key' => $roomKey,
            'taken_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
