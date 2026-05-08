<?php

return [

    'paypal' => [
        'receiver_email' => env('PAYPAL_RECEIVER_EMAIL', 'payments@example.com'),
        'receiver_name'  => env('PAYPAL_RECEIVER_NAME', 'Subscription Payments'),
    ],

    'proof' => [
        // Max screenshot size in kilobytes (default 5 MB)
        'max_size_kb'    => env('PROOF_MAX_SIZE_KB', 5120),
        'allowed_mimes'  => ['jpg', 'jpeg', 'png', 'webp'],
        'storage_disk'   => env('PROOF_STORAGE_DISK', 'public'),
        'storage_path'   => 'payment-proofs',
    ],

];
