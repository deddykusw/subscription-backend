<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly ReferralService     $referralService,
    ) {}

    /**
     * Creates a new user from an external presence-server identity,
     * immediately grants a free trial, and issues a Sanctum token.
     *
     * The entire operation runs inside a transaction: if trial creation
     * fails (e.g. no active plans), the user row is rolled back too.
     *
     * @param array{ external_user_id: string, email: string, name: string, device_name: string, referral_code?: string|null } $data
     * @return array{ user: User, token: string, subscription: array, referral_code: string }
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'external_user_id' => $data['external_user_id'],
                'name'             => $data['name'],
                'email'            => $data['email'],
                // password is NOT NULL in the schema but never used for auth;
                // a random hash ensures the column constraint is satisfied.
                'password'         => bcrypt(Str::random(32)),
            ]);

            $this->subscriptionService->createTrialSubscription($user);

            // Generate referral code for every new user immediately.
            $userReferral = $this->referralService->generateReferralCode($user);

            // Apply referral code if the caller provided one.
            // Errors are swallowed so a bad/duplicate code never blocks registration.
            if (! empty($data['referral_code'])) {
                try {
                    $this->referralService->applyReferralCode($user, $data['referral_code']);
                } catch (\InvalidArgumentException $e) {
                    Log::warning("Referral code not applied for user #{$user->id}: {$e->getMessage()}");
                }
            }

            $token = $user->createToken($data['device_name'])->plainTextToken;

            return [
                'user'          => $user,
                'token'         => $token,
                'subscription'  => $this->subscriptionService->getSubscriptionStatus($user),
                'referral_code' => $userReferral->referral_code,
            ];
        });
    }

    /**
     * Looks up a user by external_user_id (preferred) or email, then issues
     * a new Sanctum token for the given device.
     *
     * Returns null when no matching user is found — callers should treat
     * this as 401 Unauthorized rather than exposing which identifier was wrong.
     *
     * @param array{ external_user_id?: string, email?: string, device_name: string } $data
     * @return array{ user: User, token: string, subscription: array }|null
     */
    public function login(array $data): ?array
    {
        $user = $this->findUserForLogin($data);

        if ($user === null) {
            return null;
        }

        $token = $user->createToken($data['device_name'])->plainTextToken;

        return [
            'user'         => $user,
            'token'        => $token,
            'subscription' => $this->subscriptionService->getSubscriptionStatus($user),
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolves a user from either identifier.
     * external_user_id takes priority when both are present.
     */
    private function findUserForLogin(array $data): ?User
    {
        if (! empty($data['external_user_id'])) {
            return User::where('external_user_id', $data['external_user_id'])->first();
        }

        return User::where('email', $data['email'])->first();
    }
}
