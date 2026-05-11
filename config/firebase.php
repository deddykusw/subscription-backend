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

];
