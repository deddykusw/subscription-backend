<?php

/**
 * Mobile renewal checkout (attendance_token — no Sanctum).
 */
return [

    'upload_deadline_hours' => (int) env('RENEWAL_UPLOAD_DEADLINE_HOURS', 48),

    'rate_limit' => [
        'checkout_per_minute' => (int) env('RENEWAL_RATE_LIMIT_CHECKOUT_PER_MIN', 10),
        'proof_per_minute' => (int) env('RENEWAL_RATE_LIMIT_PROOF_PER_MIN', 8),
    ],

    'proof' => [
        'max_size_kb' => (int) env('RENEWAL_PROOF_MAX_SIZE_KB', 5120),
        'storage_disk' => env('RENEWAL_PROOF_STORAGE_DISK', 'local'),
        'storage_path' => 'renewal-payment-proofs',
        'allowed_mimes' => ['jpeg', 'jpg', 'png'],
    ],

    /*
     * Instruction lines for GET /renewals/payment-info (before checkout exists).
     * Placeholders: {amount}, {currency}, {period_label}
     */
    'payment_info_instructions' => [
        'Open PayPal and send {currency} {amount} for the {period_label} renewal.',
        'Use "Friends & Family" when applicable to reduce fees.',
        'After payment, create a checkout in the app, then upload your proof image before the upload deadline.',
    ],

    /*
     * Shown after checkout is created; {checkout_id} is the UUID string.
     */
    'checkout_instructions' => [
        'Your renewal checkout ID is: {checkout_id}.',
        'Include this checkout ID in the payment note if the payment method supports notes.',
        'Upload your payment proof image via POST /api/v1/renewals/{checkout_id}/payment-proof (multipart) before the deadline.',
    ],
];
