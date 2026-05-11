<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging (HTTP v1)
    |--------------------------------------------------------------------------
    |
    | Path to the Google service account JSON (same file used by Firebase Admin SDK).
    | Required for admin "push notification" sends from the dashboard.
    |
    | Example: FIREBASE_CREDENTIALS=/var/secrets/firebase-adminsdk.json
    | Relative paths are resolved from the application base path.
    |
    */
    'credentials' => env('FIREBASE_CREDENTIALS'),

    /*
    |--------------------------------------------------------------------------
    | FCM after admin → user chat message
    |--------------------------------------------------------------------------
    |
    | Queue connection name used only for {@see \App\Jobs\SendAdminMessageFcmPushJob}.
    | Default "sync" runs FCM in the same HTTP request (no worker) — same practical
    | behaviour as the admin "Push" page. Set to "database", "redis", etc. and run
    | a queue worker if you want async delivery + retries.
    |
    */
    'admin_message_fcm_connection' => env('FIREBASE_ADMIN_MESSAGE_QUEUE_CONNECTION', 'sync'),

];
