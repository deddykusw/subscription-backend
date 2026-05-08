<?php

/**
 * CORS (Cross-Origin Resource Sharing) configuration.
 *
 * Mobile apps (Android / iOS) that communicate over HTTP do not send an
 * Origin header, so CORS does not apply to them. However React Native /
 * Flutter WebView flows, Electron wrappers, and browser-based debug tools
 * (Postman web, Swagger UI) do send Origin headers.
 *
 * Production setup:
 *   CORS_ALLOWED_ORIGINS=https://app.example.com,https://admin.example.com
 *
 * Development (allow all):
 *   CORS_ALLOWED_ORIGINS=*
 *
 * Multiple origins are comma-separated. Whitespace around commas is trimmed.
 */

return [

    // -------------------------------------------------------------------------
    // Paths that trigger CORS headers
    // -------------------------------------------------------------------------

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    // -------------------------------------------------------------------------
    // Allowed HTTP methods
    // -------------------------------------------------------------------------

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // -------------------------------------------------------------------------
    // Allowed origins
    //
    // '*' allows any origin (suitable for public APIs and development).
    // Restrict to specific domains in production to prevent CSRF from the web.
    // -------------------------------------------------------------------------

    'allowed_origins' => (function (): array {
        $raw = env('CORS_ALLOWED_ORIGINS', '*');

        if ($raw === '*') {
            return ['*'];
        }

        // Trim whitespace and filter empty segments from "origin1, origin2, "
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    })(),

    // -------------------------------------------------------------------------
    // Allowed origin patterns (regex)
    //
    // Useful for wildcard subdomains, e.g.:
    //   '#^https://[a-z0-9-]+\.example\.com$#'
    // -------------------------------------------------------------------------

    'allowed_origins_patterns' => array_values(array_filter(
        array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS_PATTERNS', '')))
    )),

    // -------------------------------------------------------------------------
    // Allowed request headers
    //
    // Standard headers + mobile-specific:
    //   Authorization  — Sanctum bearer token
    //   X-Device-ID    — device identifier sent by the mobile app
    //   X-App-Version  — app version string (useful for analytics / deprecation)
    // -------------------------------------------------------------------------

    'allowed_headers' => [
        'Content-Type',
        'Accept',
        'Authorization',
        'X-Requested-With',
        'X-Device-ID',
        'X-App-Version',
    ],

    // -------------------------------------------------------------------------
    // Exposed response headers
    //
    // Headers the browser is allowed to read from the response.
    // X-RateLimit-* allows mobile apps to implement client-side throttling.
    // -------------------------------------------------------------------------

    'exposed_headers' => [
        'Authorization',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
    ],

    // -------------------------------------------------------------------------
    // Preflight cache duration (seconds)
    //
    // Browsers cache the preflight OPTIONS response for this long.
    // 86400 = 24 hours. Reduce when debugging CORS issues.
    // -------------------------------------------------------------------------

    'max_age' => (int) env('CORS_MAX_AGE', 86400),

    // -------------------------------------------------------------------------
    // Credentials
    //
    // Set to true only when using cookie-based Sanctum (SPA).
    // Token-based mobile clients do NOT need this — keep false.
    // When true, allowed_origins cannot contain '*'.
    // -------------------------------------------------------------------------

    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),

];
