<?php

namespace App\Http\Requests\Admin\Referral;

use Illuminate\Foundation\Http\FormRequest;

class CancelCommissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // guarded by `admin` middleware on the route
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A cancellation reason is required.',
        ];
    }
}
