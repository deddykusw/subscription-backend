<?php

namespace App\Http\Controllers\Api;

use App\Services\ExternalAuthService;
use App\Services\FcmDeviceRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * POST /api/v1/notifications/fcm-token
 *
 * Registers or refreshes an FCM device token for the user resolved from
 * {@see ExternalAuthService::resolveLocalUserFromAttendanceToken()} — same
 * attendance session rules as voucher redeem and messaging (sesi-aja + row in
 * {@code attendance_profiles}).
 *
 * HTTP status policy (aligned with voucher / public attendance-token flows):
 * - **200** — success or idempotent no-op (same token posted again).
 * - **401** — {@code attendance_token} missing, invalid, expired, or no local profile (same semantics as voucher redeem).
 * - **413** — raw body larger than 16KB (see {@see \App\Http\Middleware\RejectOversizedJsonBody}).
 * - **422** — validation failed ({@code fcm_token} too short/long, missing fields, etc.).
 * - **500** — unexpected server error (no stack trace in JSON).
 *
 * **Platform:** values are compared case-insensitively. Must be one of {@code android},
 * {@code ios}, {@code other}. Unknown values are **normalized to** {@code other}
 * (documented contract for permissive clients / gateway typos).
 *
 * **Rate limiting:** optional per-IP throttle on the route (see {@code routes/api.php});
 * stricter per-token limits can be added at the edge/gateway.
 */
class FcmTokenController extends ApiController
{
    public function __construct(
        private readonly ExternalAuthService $externalAuthService,
        private readonly FcmDeviceRegistrationService $fcmDeviceRegistrationService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'attendance_token' => ['required', 'string', 'max:8192'],
            'fcm_token' => ['required', 'string', 'min:32', 'max:4096'],
            'platform' => ['required', 'string', 'max:32'],
            'app_version' => ['nullable', 'string', 'max:128'],
        ], [
            'fcm_token.min' => 'FCM token is too short or invalid.',
            'fcm_token.max' => 'FCM token exceeds maximum length.',
        ]);

        if ($validator->fails()) {
            return $this->error(
                $validator->errors()->first() ?? 'Validation failed.',
                422,
                $validator->errors()->toArray(),
            );
        }

        /** @var array{attendance_token: string, fcm_token: string, platform: string, app_version?: string|null} $data */
        $data = $validator->validated();

        $platform = strtolower(trim($data['platform']));
        if (! in_array($platform, ['android', 'ios', 'other'], true)) {
            $platform = 'other';
        }

        try {
            $user = $this->externalAuthService->resolveLocalUserFromAttendanceToken($data['attendance_token']);
        } catch (\RuntimeException $e) {
            Log::warning('fcm_token_attendance_auth_failed', [
                'reason' => $e->getMessage(),
                'attendance_token_prefix' => $this->maskToken($data['attendance_token']),
            ]);

            return $this->error($e->getMessage(), 401);
        }

        try {
            $registration = $this->fcmDeviceRegistrationService->upsertForUser(
                $user,
                $data['fcm_token'],
                $platform,
                $data['app_version'] ?? null,
            );
        } catch (\Throwable $e) {
            Log::error('fcm_token_registration_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Unable to register FCM token.', 500);
        }

        return $this->success(
            [
                'id' => (string) $registration->id,
                'updated_at' => $registration->updated_at?->toIso8601String(),
            ],
            'FCM token registered',
        );
    }

    private function maskToken(string $token): string
    {
        $len = strlen($token);
        if ($len <= 12) {
            return '***';
        }

        return substr($token, 0, 4).'…'.substr($token, -4)." (len={$len})";
    }
}
