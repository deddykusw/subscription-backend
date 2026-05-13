<?php

namespace App\Http\Requests\Renewal;

use Illuminate\Foundation\Http\FormRequest;

class RenewalPaymentProofRequest extends FormRequest
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
        $maxKb = (int) config('renewals.proof.max_size_kb', 5120);

        return [
            'file' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:'.$maxKb],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Bukti pembayaran (file gambar) wajib diunggah.',
            'file.image' => 'File harus berupa gambar.',
        ];
    }
}
