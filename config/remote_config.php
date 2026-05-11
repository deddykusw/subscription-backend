<?php

return [
    'version' =>  env('REMOTE_CONFIG_VERSION', 1),

    'presensi' => [
        'base_url'         => env('REMOTE_CONFIG_PRESENSI_BASE_URL'),
        'timeout_seconds'  => (int) env('REMOTE_CONFIG_PRESENSI_TIMEOUT_SECONDS', 30),
    ],

    'subscription' => [
        'base_url'         => env('REMOTE_CONFIG_SUBSCRIPTION_BASE_URL'),
        'timeout_seconds'  => (int) env('REMOTE_CONFIG_SUBSCRIPTION_TIMEOUT_SECONDS', 30),
    ],
];

