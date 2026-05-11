<?php

namespace App\Services;

use App\Models\FcmDeviceRegistration;
use Illuminate\Http\Client\Response;
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

        $success = 0;
        $failed = 0;
        $errors = [];

        foreach ($fcmTokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                $failed++;

                continue;
            }

            $messageInner = $this->buildFcmMessageInner($token, $title, $body, $data);

            try {
                $response = $this->postMessagesSend($accessToken, $projectId, $messageInner);

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
     * Like {@see sendToTokens} but deletes local {@see FcmDeviceRegistration} rows when FCM
     * reports an unregistered / invalid token for the given recipient user.
     *
     * @param  list<string>  $fcmTokens
     * @param  array<string, string>  $data
     * @return array{success: int, failed: int, pruned: int, errors: list<string>}
     */
    public function sendNotificationWithPruning(
        array $fcmTokens,
        string $title,
        string $body,
        array $data,
        int $recipientUserId,
    ): array {
        $creds = $this->credentials();
        if ($creds === null) {
            return ['success' => 0, 'failed' => count($fcmTokens), 'pruned' => 0, 'errors' => ['Firebase credentials not configured.']];
        }

        $accessToken = $this->getAccessToken($creds);
        if ($accessToken === null) {
            return ['success' => 0, 'failed' => count($fcmTokens), 'pruned' => 0, 'errors' => ['Unable to obtain Google OAuth access token.']];
        }

        $projectId = (string) $creds['project_id'];

        $success = 0;
        $failed = 0;
        $pruned = 0;
        $errors = [];

        foreach ($fcmTokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                $failed++;

                continue;
            }

            $messageInner = $this->buildFcmMessageInner($token, $title, $body, $data);

            try {
                $response = $this->postMessagesSend($accessToken, $projectId, $messageInner);
                $json = $response->json();

                if ($response->successful()) {
                    $success++;
                    Log::info('fcm_push_sent', [
                        'token_suffix' => strlen($token) > 6 ? substr($token, -6) : '***',
                        'context' => 'admin_message',
                    ]);

                    continue;
                }

                $failed++;
                $msg = $this->summarizeFcmError($json, $response->status());
                $errors[] = $msg;
                Log::warning('fcm_push_failed', [
                    'http_status' => $response->status(),
                    'token_suffix' => strlen($token) > 6 ? substr($token, -6) : '***',
                    'detail' => $msg,
                    'context' => 'admin_message',
                ]);

                if ($this->responseIndicatesInvalidToken(is_array($json) ? $json : null, $response->status())) {
                    $deleted = FcmDeviceRegistration::query()
                        ->where('user_id', $recipientUserId)
                        ->where('fcm_token', $token)
                        ->delete();
                    if ($deleted > 0) {
                        $pruned += $deleted;
                        Log::info('fcm_registration_pruned_invalid_token', [
                            'user_id' => $recipientUserId,
                            'token_suffix' => strlen($token) > 6 ? substr($token, -6) : '***',
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = 'Network error: '.$e->getMessage();
                Log::error('fcm_push_exception', ['error' => $e->getMessage(), 'context' => 'admin_message']);
            }
        }

        return [
            'success' => $success,
            'failed' => $failed,
            'pruned' => $pruned,
            'errors' => array_values(array_unique(array_slice($errors, 0, 20))),
        ];
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, mixed>
     */
    private function buildFcmMessageInner(string $token, string $title, string $body, array $data): array
    {
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

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function postMessagesSend(string $accessToken, string $projectId, array $messageInner): Response
    {
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        return Http::timeout(20)
            ->withToken($accessToken)
            ->acceptJson()
            ->post($url, ['message' => $messageInner]);
    }

    /** @param  array<string, mixed>|null  $json */
    private function responseIndicatesInvalidToken(?array $json, int $httpStatus): bool
    {
        if ($httpStatus === 404) {
            return true;
        }

        $status = data_get($json, 'error.status');
        if ($status === 'NOT_FOUND') {
            return true;
        }

        $errorCode = data_get($json, 'error.details.0.errorCode');
        if ($errorCode === 'UNREGISTERED' || $errorCode === 'SENDER_ID_MISMATCH') {
            return true;
        }

        if ($errorCode === 'INVALID_ARGUMENT' || $httpStatus === 400) {
            $msg = strtolower((string) data_get($json, 'error.message', ''));

            return str_contains($msg, 'registration token')
                || str_contains($msg, 'not a valid fcm')
                || str_contains($msg, 'not registered');
        }

        return false;
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
        if ($json === null) {
            return "HTTP {$httpStatus}";
        }

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
