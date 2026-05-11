<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends FCM HTTP v1 notifications using a Google service account JSON file.
 *
 * OAuth access tokens are cached (~55 minutes). Does not log raw device tokens.
 */
class FcmPushService
{
    private const OAUTH_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** @return array<string, mixed>|null */
    private function credentials(): ?array
    {
        $path = config('firebase.credentials');
        if ($path === null || $path === '') {
            return null;
        }

        if (! str_starts_with((string) $path, '/')) {
            $path = base_path((string) $path);
        }

        if (! is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return null;
        }

        foreach (['project_id', 'client_email', 'private_key'] as $key) {
            if (empty($decoded[$key]) || ! is_string($decoded[$key])) {
                return null;
            }
        }

        return $decoded;
    }

    public function isConfigured(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * @param  list<string>  $fcmTokens  Distinct FCM registration tokens.
     * @param  array<string, string>  $data  String values only (FCM requirement).
     * @return array{success: int, failed: int, errors: list<string>}
     */
    public function sendToTokens(array $fcmTokens, string $title, string $body, array $data = []): array
    {
        $creds = $this->credentials();
        if ($creds === null) {
            return ['success' => 0, 'failed' => count($fcmTokens), 'errors' => ['Firebase credentials not configured.']];
        }

        $accessToken = $this->getAccessToken($creds);
        if ($accessToken === null) {
            return ['success' => 0, 'failed' => count($fcmTokens), 'errors' => ['Unable to obtain Google OAuth access token.']];
        }

        $projectId = (string) $creds['project_id'];
        $urlBase = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $success = 0;
        $failed = 0;
        $errors = [];

        foreach ($fcmTokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                $failed++;

                continue;
            }

            $message = [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'android' => [
                    'priority' => 'HIGH',
                ],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10',
                    ],
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                        ],
                    ],
                ],
            ];

            if ($data !== []) {
                $message['data'] = $data;
            }

            $payload = ['message' => $message];

            try {
                $response = Http::timeout(20)
                    ->withToken($accessToken)
                    ->acceptJson()
                    ->post($urlBase, $payload);

                if ($response->successful()) {
                    $success++;
                    Log::info('fcm_push_sent', [
                        'token_suffix' => strlen($token) > 6 ? substr($token, -6) : '***',
                    ]);
                } else {
                    $failed++;
                    $msg = $this->summarizeFcmError($response->json(), $response->status());
                    $errors[] = $msg;
                    Log::warning('fcm_push_failed', [
                        'http_status' => $response->status(),
                        'token_suffix' => strlen($token) > 6 ? substr($token, -6) : '***',
                        'detail' => $msg,
                    ]);
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = 'Network error: '.$e->getMessage();
                Log::error('fcm_push_exception', ['error' => $e->getMessage()]);
            }
        }

        return [
            'success' => $success,
            'failed' => $failed,
            'errors' => array_values(array_unique(array_slice($errors, 0, 20))),
        ];
    }

    /**
     * @param  array<string, mixed>  $creds
     */
    private function getAccessToken(array $creds): ?string
    {
        $cacheKey = 'firebase_messaging_access_token:'.hash('sha256', (string) $creds['client_email']);

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $jwt = $this->buildServiceAccountJwt(
            (string) $creds['client_email'],
            (string) $creds['private_key'],
        );

        if ($jwt === null) {
            return null;
        }

        try {
            $response = Http::asForm()->timeout(15)->post(self::OAUTH_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
        } catch (\Throwable $e) {
            Log::error('fcm_oauth_request_failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('fcm_oauth_rejected', [
                'http_status' => $response->status(),
                'body_excerpt' => substr($response->body(), 0, 200),
            ]);

            return null;
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            return null;
        }

        $ttl = min((int) $response->json('expires_in', 3600) - 120, 3300);
        $ttl = max($ttl, 60);
        Cache::put($cacheKey, $token, now()->addSeconds($ttl));

        return $token;
    }

    private function buildServiceAccountJwt(string $clientEmail, string $privateKeyPem): ?string
    {
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'RS256'], JSON_THROW_ON_ERROR));
        $now = time();
        $payload = $this->base64UrlEncode(json_encode([
            'iss' => $clientEmail,
            'sub' => $clientEmail,
            'aud' => self::OAUTH_URL,
            'iat' => $now,
            'exp' => $now + 3600,
            'scope' => self::SCOPE,
        ], JSON_THROW_ON_ERROR));

        $data = $header.'.'.$payload;

        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            Log::error('fcm_invalid_private_key_openssl');

            return null;
        }

        $signature = '';
        $ok = openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (! $ok) {
            return null;
        }

        return $data.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** @param  array<string, mixed>|null  $json */
    private function summarizeFcmError(?array $json, int $httpStatus): string
    {
        $detail = $json['error']['message'] ?? null;
        if (is_string($detail) && $detail !== '') {
            return "HTTP {$httpStatus}: {$detail}";
        }

        $first = $json['error']['details'][0]['errorCode'] ?? null;
        if (is_string($first) && $first !== '') {
            return "HTTP {$httpStatus}: {$first}";
        }

        return "HTTP {$httpStatus}";
    }
}
