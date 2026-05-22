<?php

namespace App\Http\Requests\Renewal;

use Illuminate\Foundation\Http\FormRequest;

class RenewalCancelRequest extends FormRequest
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
        return [];
    }
}
