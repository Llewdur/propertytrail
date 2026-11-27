<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MergeRoomRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['into_room_id' => ['required', 'integer']];
    }
}
