<?php

namespace App\Services;

use App\Models\AttendanceProfile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExternalAuthService
{
    // How long (minutes) a successful token validation result is cached.
    private const VALIDATION_CACHE_MINUTES = 10;

    // How long (minutes) before we force a re-validation against the attendance server.
    private const REVALIDATION_THRESHOLD_MINUTES = 60;

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly ReferralService     $referralService,
    ) {}

    // =========================================================================
    // 1. Validate token against attendance server
    // =========================================================================

    /**
     * Calls GET /api/user/profile on the attendance server using the provided
     * bearer token. Returns the profile data array on success, or false.
     *
     * Result is cached for {@see VALIDATION_CACHE_MINUTES} to avoid hammering
     * the attendance server on every request.
     *
     * @return array<string, mixed>|false
     */
    public function validateAttendanceToken(string $token): array|false
    {
        $cacheKey = 'attendance_token:' . md5($token);
        // dd(config('services.attendance.url') . '/api/user/profile');

        // return Cache::remember($cacheKey, self::VALIDATION_CACHE_MINUTES * 60, function () use ($token) {
        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get(config('services.attendance.url') . '/api/user/profile');
            // dd($response->body());
            if ($response->successful() && $response->json('status') === true) {
                return $response->json('data');
            }

            return false;
        } catch (\Throwable $e) {
            Log::error('Attendance token validation failed: ' . $e->getMessage());
            return false;
        }
        // });
    }

    /**
     * Validates the presensi session by calling the attendance server
     * {@see config('services.attendance.sesi_aja_path')} with the token as Bearer.
     *
     * Used before sensitive actions (e.g. voucher redeem). Fails closed on HTTP
     * errors, 401, or JSON `{ "status": false }` when present.
     */
    public function validateAttendanceSessionSesiAja(string $token): bool
    {
        $base = rtrim((string) config('services.attendance.url'), '/');
        $path = (string) config('services.attendance.sesi_aja_path', '/api/absen/sesi-aja');
        $path = '/'.ltrim($path, '/');
        $url = $base.$path;
        $timeout = (int) config('services.attendance.timeout', 10);
        $method = strtoupper((string) config('services.attendance.sesi_aja_method', 'GET'));

        try {
            $pending = Http::withToken($token)->timeout($timeout)->acceptJson();

            $response = match ($method) {
                'POST' => $pending->post($url),
                default => $pending->get($url),
            };

            if (! $response->successful()) {
                Log::warning('Attendance sesi-aja rejected: HTTP '.$response->status());

                return false;
            }

            if ($response->json('status') === false) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Attendance sesi-aja request failed: '.$e->getMessage());

            return false;
        }
    }

    // =========================================================================
    // 2. Login to attendance server (get fresh token using credentials)
    // =========================================================================

    /**
     * Calls the attendance server login endpoint with username + password
     * and returns the raw token string.
     *
     * Expected attendance server response:
     * { "status": true, "message": "Berhasil login", "data": [], "token": "..." }
     *
     * @throws \RuntimeException  On invalid credentials or server error.
     */
    public function loginToAttendanceServer(string $username, string $password): string
    {
        try {
            $response = Http::timeout(10)
                ->post(
                    config('services.attendance.url') . config('services.attendance.login_path', '/api/login'),
                    ['username' => $username, 'password' => $password],
                );

            if ($response->successful() && $response->json('status') === true) {
                $token = $response->json('token');

                if (! $token) {
                    throw new \RuntimeException('Server presensi tidak mengembalikan token.');
                }

                return $token;
            }

            throw new \RuntimeException(
                $response->json('message') ?? 'Login ke server presensi gagal.'
            );
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Attendance server login failed: ' . $e->getMessage());
            throw new \RuntimeException('Server presensi tidak dapat dihubungi.');
        }
    }

    /**
     * Uses the credentials stored in attendance_profiles to obtain a fresh
     * token from the attendance server, then updates the profile row.
     *
     * Call this when validateAttendanceToken() returns false (token expired).
     *
     * @throws \RuntimeException  When no credentials are stored or login fails.
     */
    public function refreshTokenFromCredentials(User $user): string
    {
        $profile = $user->attendanceProfile ?? $user->attendanceProfile()->first();

        if ($profile === null || ! $profile->attendance_username || ! $profile->attendance_password) {
            throw new \RuntimeException(
                'Tidak ada kredensial tersimpan. Silakan login ulang dari aplikasi presensi.'
            );
        }

        $newToken = $this->loginToAttendanceServer(
            $profile->attendance_username,
            $profile->attendance_password,
        );

        // Fetch fresh profile with the new token and persist everything.
        $serverProfile = $this->validateAttendanceToken($newToken);

        if ($serverProfile === false) {
            throw new \RuntimeException('Token baru dari server presensi tidak valid.');
        }

        $this->saveAttendanceProfile($user, $newToken, $serverProfile, [
            'attendance_username' => $profile->attendance_username,
            'attendance_password' => $profile->attendance_password,
        ]);

        // Bust the old token cache so future calls use the new token.
        Cache::forget('attendance_token:' . md5($profile->attendance_token));
        $user->update(['last_token_validation_at' => now()]);

        return $newToken;
    }

    // =========================================================================
    // 3. Exchange token — main entry point from the Android app
    // =========================================================================

    /**
     * Full token exchange flow:
     *
     *   1. Validate attendance token → fetch profile from attendance server
     *   2. Find or auto-create the local user
     *   3. Save attendance_profile (token + credentials if provided + all profile fields)
     *   4. Issue a Sanctum bearer token so Android can call our protected APIs
     *   5. Return user, subscription status, access gate, and the Sanctum token
     *
     * @param  string      $attendanceToken  Token obtained by Android from the attendance server.
     * @param  string      $deviceName       Label for the Sanctum token (shown in token list).
     * @param  string|null $username         Attendance server username — stored encrypted for auto-refresh.
     * @param  string|null $password         Attendance server password — stored encrypted for auto-refresh.
     * @return array
     *
     * @throws \RuntimeException  When the attendance token is invalid.
     */
    public function exchangeToken(
        string $attendanceToken,
        string $deviceName = 'mobile-app',
        ?string $username = null,
        ?string $password = null,
    ): array {
        // 1. Validate → get full profile from attendance server
        $serverProfile = $this->validateAttendanceToken($attendanceToken);

        if ($serverProfile === false) {
            throw new \RuntimeException('Token tidak valid atau server presensi tidak dapat dihubungi.');
        }

        // 2. Find or create local user
        $user = DB::transaction(function () use ($serverProfile) {
            return $this->findOrCreateUser($serverProfile);
        });

        // 3. Save attendance profile (credentials are optional but stored when provided)
        $credentials = [];
        if ($username !== null) {
            $credentials['attendance_username'] = $username;
        }
        if ($password !== null) {
            $credentials['attendance_password'] = $password;
        }
        $this->saveAttendanceProfile($user, $attendanceToken, $serverProfile, $credentials);

        // 4. Stamp last validation time
        $user->update(['last_token_validation_at' => now()]);

        // 5. Issue a fresh Sanctum token for this device
        $sanctumToken = $user->createToken($deviceName)->plainTextToken;

        // 6. Build response
        return [
            'user'               => $this->formatUser($user),
            'attendance_profile' => $this->formatProfile($user->fresh()->attendanceProfile),
            'subscription'       => $this->subscriptionService->getSubscriptionStatus($user),
            'access'             => $this->canAccessApp($user),
            'token'              => $sanctumToken,
            'token_type'         => 'Bearer',
        ];
    }

    /**
     * Returns user + attendance_profile + subscription + access by validating
     * an attendance_token, without issuing a Sanctum token.
     *
     * If the local user does not exist yet, it will be auto-created (including
     * trial + referral code) exactly like exchangeToken().
     *
     * @throws \RuntimeException  When the attendance token is invalid.
     */
    public function profileByAttendanceToken(string $attendanceToken): array
    {
        $serverProfile = $this->validateAttendanceToken($attendanceToken);

        if ($serverProfile === false) {
            throw new \RuntimeException('Token tidak valid atau server presensi tidak dapat dihubungi.');
        }

        $user = DB::transaction(function () use ($serverProfile) {
            return $this->findOrCreateUser($serverProfile);
        });

        $this->saveAttendanceProfile($user, $attendanceToken, $serverProfile);
        $user->update(['last_token_validation_at' => now()]);

        $freshUser = $user->fresh(['attendanceProfile']);

        return [
            'user'               => $this->formatUser($freshUser),
            'attendance_profile' => $this->formatProfile($freshUser->attendanceProfile),
            'subscription'       => $this->subscriptionService->getSubscriptionStatus($freshUser),
            'access'             => $this->canAccessApp($freshUser),
        ];
    }

    /**
     * Resolves the local {@see User} for flows where the client only has an
     * attendance_token (no Sanctum session): validates session via sesi-aja,
     * then loads identity from GET /api/user/profile and find-or-creates the user.
     *
     * @throws \RuntimeException When sesi-aja or profile validation fails.
     */
    public function resolveLocalUserFromAttendanceToken(string $attendanceToken): User
    {
        if (! $this->validateAttendanceSessionSesiAja($attendanceToken)) {
            throw new \RuntimeException(
                'Token presensi tidak valid atau sesi telah berakhir. Tidak dapat menggunakan voucher.'
            );
        }

        $serverProfile = $this->validateAttendanceToken($attendanceToken);

        if ($serverProfile === false) {
            throw new \RuntimeException(
                'Token presensi tidak valid atau server presensi tidak dapat dihubungi.'
            );
        }

        $user = DB::transaction(function () use ($serverProfile) {
            return $this->findOrCreateUser($serverProfile);
        });

        $this->saveAttendanceProfile($user, $attendanceToken, $serverProfile);
        $user->update(['last_token_validation_at' => now()]);

        return $user->fresh();
    }

    // =========================================================================
    // 3. Refresh — re-validate stored token, update profile, return new status
    // =========================================================================

    /**
     * Re-validates the user's stored attendance token and refreshes all
     * profile fields from the attendance server.
     *
     * Called from POST /api/v1/auth/refresh-subscription (authenticated).
     *
     * When the stored token is expired AND credentials (username + password) are
     * available, this method automatically re-logs into the attendance server to
     * obtain a new token — the user does NOT need to re-authenticate manually.
     *
     * @throws \RuntimeException  When token is expired and no credentials are stored.
     */
    public function refreshProfile(User $user): array
    {
        $profile = $user->attendanceProfile ?? $user->attendanceProfile()->first();

        if ($profile === null) {
            throw new \RuntimeException(
                'Profil presensi belum tersinkronisasi. Silakan exchange token terlebih dahulu.'
            );
        }

        // Bypass cache for an explicit refresh.
        Cache::forget('attendance_token:' . md5($profile->attendance_token));
        $serverProfile = $this->validateAttendanceToken($profile->attendance_token);

        if ($serverProfile === false) {
            // Token expired — try to auto-refresh using stored credentials.
            if ($profile->attendance_username && $profile->attendance_password) {
                $this->refreshTokenFromCredentials($user);
                $profile  = $user->fresh(['attendanceProfile'])->attendanceProfile;
                $serverProfile = $this->validateAttendanceToken($profile->attendance_token);
            }

            if ($serverProfile === false) {
                throw new \RuntimeException(
                    'Token presensi sudah kadaluarsa. Silakan login ulang dari aplikasi presensi.'
                );
            }
        }

        $this->saveAttendanceProfile($user, $profile->attendance_token, $serverProfile);
        $user->update(['last_token_validation_at' => now()]);

        $freshUser = $user->fresh(['attendanceProfile']);

        return [
            'user'               => $this->formatUser($freshUser),
            'attendance_profile' => $this->formatProfile($freshUser->attendanceProfile),
            'subscription'       => $this->subscriptionService->getSubscriptionStatus($freshUser),
            'access'             => $this->canAccessApp($freshUser),
        ];
    }

    // =========================================================================
    // 4. Access gate
    // =========================================================================

    /**
     * Determines whether a user may use the Android attendance app.
     *
     * TWO conditions must both be true:
     *   a) is_active = true on the attendance server  →  employee is still active
     *   b) has an active subscription in our system   →  organization has paid
     *
     * If the attendance profile is missing (never synced), access is denied.
     *
     * @return array{ can_access: bool, reason: string|null }
     */
    public function canAccessApp(User $user): array
    {
        $profile = $user->relationLoaded('attendanceProfile')
            ? $user->attendanceProfile
            : $user->attendanceProfile()->first();

        // Gate 1: attendance server says the employee is active
        if ($profile === null) {
            return [
                'can_access' => false,
                'reason'     => 'Profil presensi belum tersinkronisasi.',
            ];
        }

        if (! $profile->is_active) {
            return [
                'can_access' => false,
                'reason'     => 'Akun presensi tidak aktif. Hubungi administrator.',
            ];
        }

        // Gate 2: our subscription system (trial or paid)
        $subscription = $this->subscriptionService->getSubscriptionStatus($user);

        if (! $subscription['isActive']) {
            return [
                'can_access' => false,
                'reason'     => 'Langganan tidak aktif. Silakan perpanjang untuk melanjutkan.',
            ];
        }

        return ['can_access' => true, 'reason' => null];
    }

    /**
     * Lightweight access check with a 60-minute re-validation window.
     * Safe to call on every app launch without hammering the attendance server.
     *
     * @return array{ can_access: bool, reason: string|null, revalidated: bool }
     */
    public function quickAccessCheck(User $user, string $attendanceToken): array
    {
        $lastValidation = $user->last_token_validation_at;
        $needsRevalidation = $lastValidation === null
            || $lastValidation->diffInMinutes(now()) >= self::REVALIDATION_THRESHOLD_MINUTES;

        if ($needsRevalidation) {
            $serverProfile = $this->validateAttendanceToken($attendanceToken);

            if ($serverProfile === false) {
                return [
                    'can_access'  => false,
                    'reason'      => 'Token presensi tidak valid.',
                    'revalidated' => true,
                ];
            }

            $this->saveAttendanceProfile($user, $attendanceToken, $serverProfile);
            $user->update(['last_token_validation_at' => now()]);
            $user = $user->fresh(['attendanceProfile']);
        }

        return array_merge(
            $this->canAccessApp($user),
            ['revalidated' => $needsRevalidation],
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Finds an existing user by attendance server ID, username, or email.
     * Creates a new user (with trial subscription + referral code) when not found.
     *
     * MUST be called inside a DB transaction.
     */
    private function findOrCreateUser(array $serverProfile): User
    {
        $externalId = (string) $serverProfile['id'];
        $username   = $serverProfile['username'] ?? null;
        $email      = $serverProfile['email']    ?? null;
        $name       = $serverProfile['name']     ?? $username ?? 'Unknown';

        // Look up by the most stable identifier first, then fall back to weaker ones.
        $user = User::where('external_user_id', $externalId)->first()
            ?? ($username ? User::where('username', $username)->first() : null)
            ?? ($email    ? User::where('email', $email)->first()       : null);

        if ($user !== null) {
            // Keep our local copy in sync with the attendance server.
            $user->update([
                'external_user_id'    => $externalId,
                'username'            => $username,
                'email'               => $email ?? $user->email,
                'name'                => $name,
                'attendance_server_id' => (int) $serverProfile['id'],
            ]);

            return $user;
        }

        // New user — create with a random unusable password (auth is token-based).
        $user = User::create([
            'external_user_id'    => $externalId,
            'username'            => $username,
            'email'               => $email ?? "{$externalId}@attendance.local",
            'name'                => $name,
            'attendance_server_id' => (int) $serverProfile['id'],
            'password'            => bcrypt(Str::random(32)),
        ]);

        // Auto-activate trial and generate a referral code for every new user.
        $this->subscriptionService->createTrialSubscription($user);
        $this->referralService->generateReferralCode($user);

        return $user;
    }

    /**
     * Upserts the attendance_profiles row for this user.
     * Safely callable multiple times — always reflects the latest server data.
     *
     * @param  array $credentials  Optional: ['attendance_username' => ..., 'attendance_password' => ...]
     *                             When provided, credentials are stored for future auto token refresh.
     */
    public function saveAttendanceProfile(
        User $user,
        string $token,
        array $serverProfile,
        array $credentials = [],
    ): AttendanceProfile {
        $data = [
            'attendance_token'        => $token,
            'token_obtained_at'       => now(),
            'attendance_user_id'      => (int) $serverProfile['id'],
            'id_peg'                  => $serverProfile['id_peg']          ?? null,
            'is_active'               => (bool) ($serverProfile['is_active'] ?? false),
            'tipe'                    => $serverProfile['tipe']             ?? null,
            'jabatan'                 => $serverProfile['jabatan']          ?? null,
            'kode_unit_kerja'         => $serverProfile['kode_unit_kerja'] ?? null,
            'kode_uptd'               => $serverProfile['kode_uptd']       ?? null,
            'avatar_url'              => $serverProfile['avatar_url'] ?? $serverProfile['avatar'] ?? null,
            'last_integrity_check_at' => isset($serverProfile['last_integrity_check_at'])
                ? Carbon::parse($serverProfile['last_integrity_check_at'])
                : null,
        ];

        // Only overwrite stored credentials when new ones are explicitly provided.
        if (array_key_exists('attendance_username', $credentials)) {
            $data['attendance_username'] = $credentials['attendance_username'];
        }
        if (array_key_exists('attendance_password', $credentials)) {
            $data['attendance_password'] = $credentials['attendance_password'];
        }

        return AttendanceProfile::updateOrCreate(['user_id' => $user->id], $data);
    }

    /** Formats a user for API responses. */
    private function formatUser(User $user): array
    {
        return [
            'id'                => $user->id,
            'external_user_id'  => $user->external_user_id,
            'attendance_server_id' => $user->attendance_server_id,
            'username'          => $user->username,
            'email'             => $user->email,
            'name'              => $user->name,
        ];
    }

    /** Formats an AttendanceProfile for API responses (never exposes the token). */
    private function formatProfile(?AttendanceProfile $profile): ?array
    {
        if ($profile === null) {
            return null;
        }

        return [
            'is_active'               => $profile->is_active,
            'tipe'                    => $profile->tipe,
            'jabatan'                 => $profile->jabatan,
            'kode_unit_kerja'         => $profile->kode_unit_kerja,
            'kode_uptd'               => $profile->kode_uptd,
            'id_peg'                  => $profile->id_peg,
            'avatar_url'              => $profile->avatar_url,
            'last_integrity_check_at' => $profile->last_integrity_check_at?->toIso8601String(),
            'token_obtained_at'       => $profile->token_obtained_at?->toIso8601String(),
        ];
    }
}
