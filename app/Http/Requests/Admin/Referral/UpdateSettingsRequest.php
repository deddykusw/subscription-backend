<?php

namespace App\Http\Requests\Admin\Referral;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // guarded by `admin` middleware on the route
    }

    public function rules(): array
    {
        return [
            'commission_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'min_payout_amount'     => ['sometimes', 'numeric', 'min:0'],
            'payout_method'         => ['sometimes', 'string', 'in:manual,auto'],
            'referral_bonus'        => ['sometimes', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'commission_percentage.max' => 'Commission percentage cannot exceed 100%.',
            'payout_method.in'          => 'Payout method must be either "manual" or "auto".',
        ];
    }
}
