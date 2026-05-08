<?php

namespace App\Http\Requests\Referral;

use App\Models\ReferralSetting;
use Illuminate\Foundation\Http\FormRequest;

class RequestPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payout_method'                => ['required', 'string', 'in:bank_transfer,paypal,gopay,ovo'],
            'payout_details'               => ['required', 'array'],
            // Bank transfer
            'payout_details.bank_name'     => ['required_if:payout_method,bank_transfer', 'string', 'max:100'],
            'payout_details.account_number'=> ['required_if:payout_method,bank_transfer', 'string', 'max:50'],
            'payout_details.account_name'  => ['required_if:payout_method,bank_transfer', 'string', 'max:255'],
            // PayPal
            'payout_details.paypal_email'  => ['required_if:payout_method,paypal', 'email', 'max:255'],
            // GoPay / OVO
            'payout_details.phone_number'  => ['required_if:payout_method,gopay', 'required_if:payout_method,ovo', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'payout_method.in'                         => 'Supported payout methods: bank_transfer, paypal, gopay, ovo.',
            'payout_details.bank_name.required_if'     => 'Bank name is required for bank transfer payouts.',
            'payout_details.account_number.required_if'=> 'Account number is required for bank transfer payouts.',
            'payout_details.account_name.required_if'  => 'Account holder name is required for bank transfer payouts.',
            'payout_details.paypal_email.required_if'  => 'A valid PayPal email is required for PayPal payouts.',
            'payout_details.phone_number.required_if'  => 'Phone number is required for GoPay/OVO payouts.',
        ];
    }
}
