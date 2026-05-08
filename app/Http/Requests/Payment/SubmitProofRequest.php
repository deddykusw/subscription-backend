<?php

namespace App\Http\Requests\Payment;

use App\Models\PaymentOrder;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKb = (int) config('payment.proof.max_size_kb', 5120);
        $mimes = implode(',', config('payment.proof.allowed_mimes', ['jpg', 'jpeg', 'png', 'webp']));

        return [
            'order_id'       => ['required', 'integer', Rule::exists('payment_orders', 'id')],
            'transaction_id' => ['required', 'string', 'max:255'],
            'paypal_email'   => ['required', 'string', 'email', 'max:255'],
            // Accept either a file upload OR an external URL — not both required.
            'screenshot'     => ['nullable', 'file', 'image', "max:{$maxKb}", "mimes:{$mimes}"],
            'screenshot_url' => ['nullable', 'string', 'url', 'max:2048'],
        ];
    }

    /**
     * Validate that:
     * 1. The order belongs to the authenticated user.
     * 2. The order is still in a submittable state (pending).
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $order = PaymentOrder::find($this->integer('order_id'));

            if (! $order) {
                return; // already caught by exists rule
            }

            if ($order->user_id !== $this->user()?->id) {
                $validator->errors()->add('order_id', 'This order does not belong to your account.');
                return;
            }

            if ($order->status->isFinal()) {
                $validator->errors()->add(
                    'order_id',
                    "This order can no longer be modified — current status: {$order->status->label()}.",
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'screenshot.image' => 'The proof screenshot must be an image file.',
            'screenshot.mimes' => 'Accepted formats: JPG, PNG, WebP.',
            'screenshot.max'   => 'The screenshot must not exceed ' . config('payment.proof.max_size_kb') . ' KB.',
        ];
    }
}
