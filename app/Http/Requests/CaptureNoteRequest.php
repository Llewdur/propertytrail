<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CaptureIssue;
use App\Support\ConditionVocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A room's condition rating, or one issue in it. */
final class CaptureNoteRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $roomKey = ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'];

        return [
            'area_room_id' => ['required_without:room_key', 'nullable', 'integer'],
            'room_key' => $roomKey,
            'room_name' => ['required_with:room_key', 'nullable', 'string', 'max:80'],
            'room_outdoor' => ['sometimes', 'boolean'],
            'adjoins_area_room_id' => ['sometimes', 'nullable', 'integer'],
            'adjoins_room_key' => $roomKey,
            'condition' => ['required_without:issue_key', 'nullable', 'string', Rule::in(ConditionVocabulary::keys())],
            'issue_key' => $roomKey,
            'issue_kind' => ['required_with:issue_key', 'nullable', 'string', Rule::in(CaptureIssue::KINDS)],
            'issue_note' => ['required_with:issue_key', 'nullable', 'string', 'max:500'],
            'taken_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
