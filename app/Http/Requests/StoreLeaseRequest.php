<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreLeaseRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lease_reference' => ['required', 'string', 'max:64'],
            'tenant_name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after:start_date'],
            'status' => ['sometimes', 'string', 'in:active,expired,terminated'],
            // The tenancy this one follows: its room list is reused, nothing else.
            'previous_lease_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
