<?php

namespace App\Http\Requests\Renewal;

use App\Enums\RenewalPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateRenewalCheckoutRequest extends FormRequest
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
            'period' => ['required', 'string', Rule::enum(RenewalPeriod::class)],
        ];
    }
}
