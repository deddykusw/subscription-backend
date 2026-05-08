<?php

namespace App\Http\Controllers\Api;

use App\Services\ExternalAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExternalAuthController extends ApiController
{
    public function __construct(private readonly ExternalAuthService $externalAuthService) {}

    // =========================================================================
    // POST /api/v1/auth/exchange-token  (public)
    // =========================================================================

    /**
     * Main entry point for the Android app after a successful attendance login.
     *
     * The Android app already has a token from the attendance server — it sends
     * only that token here (no username, no password).
     *
     * What this endpoint does:
     *   1. Validates the token against GET /api/user/profile on the attendance server
     *   2. Creates a local user if this is the first time (auto-grants 7-day trial)
     *   3. Saves / refreshes the attendance_profile (is_active, jabatan, etc.)
     *   4. Issues a Sanctum bearer token so the app can call our protected APIs
     *   5. Returns access gate result so the app can immediately decide what to show
     *
     * Request:
     * {
     *   "attendance_token": "q3IbiorhXRR...",
     *   "device_name": "Pixel 7"            // optional, defaults to "mobile-app"
     * }
     *
     * Response 200:
     * {
     *   "success": true,
     *   "data": {
     *     "token":              "1|abc...",   ← Sanctum token — save this in Android
     *     "token_type":         "Bearer",
     *     "user":               { id, name, username, email, ... },
     *     "attendance_profile": { is_active, jabatan, tipe, kode_unit_kerja, ... },
     *     "subscription":       { status, isActive, remainingDays, ... },
     *     "access":             { can_access: true, reason: null }
     *   }
     * }
     */
    public function exchangeToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'attendance_token' => ['required', 'string'],
            'device_name'      => ['nullable', 'string', 'max:255'],
            // Optional credentials — stored for automatic token refresh.
            'username'         => ['nullable', 'string', 'max:255'],
            'password'         => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->externalAuthService->exchangeToken(
                $validated['attendance_token'],
                $validated['device_name'] ?? 'mobile-app',
                $validated['username']    ?? null,
                $validated['password']    ?? null,
            );

            return $this->success($result, 'Login berhasil.');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 401);
        }
    }

    // =========================================================================
    // POST /api/v1/auth/validate-access  (public)
    // =========================================================================

    /**
     * Lightweight gate check — answers: "can this user open the app right now?"
     *
     * Re-validation against the attendance server is skipped if the user was
     * validated within the last 60 minutes, so this is safe to call on every
     * app launch without hammering the attendance server.
     *
     * Request:
     * {
     *   "attendance_token":  "q3IbiorhXRR...",
     *   "external_user_id":  "8513"
     * }
     *
     * Response 200:
     * {
     *   "success": true,
     *   "data": {
     *     "can_access":  true,
     *     "reason":      null,
     *     "revalidated": false   ← true when we actually hit the attendance server
     *   }
     * }
     */
    public function validateAccess(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'attendance_token' => ['required', 'string'],
            'external_user_id' => ['required', 'string'],
        ]);

        $user = \App\Models\User::where('external_user_id', $validated['external_user_id'])
            ->first();

        if ($user === null) {
            return $this->error('User tidak ditemukan. Silakan exchange token terlebih dahulu.', 404);
        }

        $result = $this->externalAuthService->quickAccessCheck(
            $user,
            $validated['attendance_token'],
        );

        return $this->success($result);
    }

    // =========================================================================
    // POST /api/v1/auth/refresh-subscription  (protected — auth:sanctum)
    // =========================================================================

    /**
     * Forces a re-sync of the authenticated user's attendance profile and
     * returns fresh subscription + access status.
     *
     * Use this when:
     *   - The app resumes after a long background period
     *   - The user reports their access status looks stale
     *   - An admin has manually changed the user's subscription
     *
     * Uses the token stored in attendance_profiles — no request body needed.
     *
     * Response 200:
     * {
     *   "success": true,
     *   "data": {
     *     "user":               { ... },
     *     "attendance_profile": { is_active, jabatan, ... },
     *     "subscription":       { status, isActive, remainingDays, ... },
     *     "access":             { can_access: bool, reason: string|null }
     *   }
     * }
     *
     * Response 401 when the stored attendance token has expired (user must
     * re-login to the attendance server from the Android app).
     */
    public function refreshSubscription(Request $request): JsonResponse
    {
        try {
            $result = $this->externalAuthService->refreshProfile($request->user());

            return $this->success($result, 'Profile berhasil diperbarui.');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 401);
        }
    }
}
