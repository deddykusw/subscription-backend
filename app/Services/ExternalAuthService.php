<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;


class ExternalAuthService
{
    /**
     * Validate token dengan attendance server
     * @param string $token
     * @return array|false User data dari attendance server atau false jika invalid
     */
    public function validateAttendanceToken(string $token)
    {
        try {
            // Call attendance server: GET /api/user/profile
            // Headers: Authorization: Bearer {token}
            $response = Http::withToken($token)
                ->timeout(10)
                ->get(config('services.attendance.url') . '/api/user/profile');

            if ($response->successful() && $response->json('status') === true) {
                return $response->json('data');
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Attendance token validation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Find atau create user berdasarkan data dari attendance server
     * @param array $attendanceUserData
     * @return User
     */
    public function findOrCreateUser(array $attendanceUserData)
    {
        // Extract data
        $externalId = $attendanceUserData['id'];
        $username = $attendanceUserData['username'] ?? null;
        $email = $attendanceUserData['email'];
        $name = $attendanceUserData['name'];

        // Find user by external_user_id, username, atau email
        $user = User::where('external_user_id', $externalId)
            ->orWhere('username', $username)
            ->orWhere('email', $email)
            ->first();

        if ($user) {
            // Update user data jika ada perubahan
            $user->update([
                'external_user_id' => $externalId,
                'username' => $username,
                'email' => $email,
                'name' => $name,
                'attendance_server_id' => $externalId,
                'last_token_validation_at' => now(),
            ]);
        } else {
            // Create new user dengan trial subscription
            DB::transaction(function () use (&$user, $externalId, $username, $email, $name) {
                $user = User::create([
                    'external_user_id' => $externalId,
                    'username' => $username,
                    'email' => $email,
                    'name' => $name,
                    'attendance_server_id' => $externalId,
                    'last_token_validation_at' => now(),
                ]);

                // Auto-activate trial
                app(SubscriptionService::class)->createTrialSubscription($user);

                // Generate referral code
                app(ReferralService::class)->generateReferralCode($user);
            });
        }

        return $user;
    }

    /**
     * Exchange attendance token untuk get subscription status
     * @param string $attendanceToken
     * @param array $userData Optional user data dari mobile app
     * @return array
     */
    public function exchangeToken(string $attendanceToken, ?array $userData = null)
    {
        // Validate token dengan attendance server
        $attendanceUserData = $this->validateAttendanceToken($attendanceToken);

        if (!$attendanceUserData) {
            throw new \Exception('Invalid attendance token');
        }

        // Override dengan user data dari request jika ada
        if ($userData) {
            $attendanceUserData = array_merge($attendanceUserData, [
                'id' => $userData['external_user_id'] ?? $attendanceUserData['id'],
            ]);
        }

        // Find or create user
        $user = $this->findOrCreateUser($attendanceUserData);

        // Get subscription status
        $subscriptionService = app(SubscriptionService::class);
        $subscription = $subscriptionService->getSubscriptionStatus($user);

        // Get referral info (optional)
        $referral = $user->userReferral()->first();

        return [
            'user' => [
                'id' => $user->id,
                'external_user_id' => $user->external_user_id,
                'username' => $user->username,
                'email' => $user->email,
                'name' => $user->name,
            ],
            'subscription' => $subscription,
            'referral' => $referral ? [
                'referral_code' => $referral->referral_code,
                'total_referrals' => $referral->total_referrals,
                'total_earnings' => $referral->total_earnings,
            ] : null,
        ];
    }

    /**
     * Check apakah user bisa akses fitur attendance
     * @param User $user
     * @return array ['can_access' => bool, 'reason' => string|null]
     */
    public function canAccessAttendance(User $user)
    {
        $subscription = app(SubscriptionService::class)
            ->getSubscriptionStatus($user);

        if (
            $subscription['is_active'] &&
            in_array($subscription['status'], ['trial', 'active'])
        ) {
            return [
                'can_access' => true,
                'reason' => null
            ];
        }

        return [
            'can_access' => false,
            'reason' => 'Subscription expired. Please renew to continue.'
        ];
    }

    // In ExternalAuthService.php

    protected function validateAttendanceTokenWithCache(string $token)
    {
        $cacheKey = "attendance_token_valid:" . md5($token);

        return Cache::remember($cacheKey, 600, function () use ($token) {
            return $this->validateAttendanceToken($token);
        });
    }

    protected function getSubscriptionStatusWithCache(User $user)
    {
        $cacheKey = "subscription_status:" . $user->id;

        return Cache::remember($cacheKey, 300, function () use ($user) {
            return app(SubscriptionService::class)
                ->getSubscriptionStatus($user);
        });
    }
}
