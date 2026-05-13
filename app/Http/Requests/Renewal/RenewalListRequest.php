<?php

namespace App\Http\Requests\Renewal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RenewalListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'status' => [
                'sometimes',
                'string',
                Rule::in(['pending_payment', 'awaiting_review', 'verified', 'rejected', 'all']),
            ],
        ];
    }
}
