<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\AuthService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function __construct(
        private readonly AuthService         $authService,
        private readonly SubscriptionService $subscriptionService,
    ) {}

    /**
     * POST /api/v1/auth/register
     *
     * Registers a new user identified by their external presence-server ID,
     * automatically grants a 7-day free trial, and returns a Sanctum bearer token.
     *
     * Response 201:
     * {
     *   "success": true,
     *   "message": "Registration successful.",
     *   "data": {
     *     "user":  { id, name, email, external_user_id, created_at },
     *     "token": "1|abc...",
     *     "token_type": "Bearer",
     *     "subscription": { status, isActive, remainingDays, ... }
     *   }
     * }
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return $this->success(
            $this->buildAuthPayload($result),
            'Registration successful.',
            201,
        );
    }

    /**
     * POST /api/v1/auth/login
     *
     * Authenticates by external_user_id (preferred) or email.
     * Returns a new Sanctum bearer token and the current subscription status.
     *
     * Response 200:
     * {
     *   "success": true,
     *   "message": "Login successful.",
     *   "data": {
     *     "user":  { ... },
     *     "token": "2|xyz...",
     *     "token_type": "Bearer",
     *     "subscription": { status, isActive, remainingDays, ... }
     *   }
     * }
     *
     * Response 401 when no matching user is found.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());

        if ($result === null) {
            return $this->error('Invalid credentials.', 401);
        }

        return $this->success(
            $this->buildAuthPayload($result),
            'Login successful.',
        );
    }

    /**
     * POST /api/v1/auth/logout
     *
     * Revokes the token that was used for this request.
     * Other tokens (other devices) remain valid.
     *
     * Requires: Authorization: Bearer {token}
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(message: 'Logged out successfully.');
    }

    /**
     * GET /api/v1/auth/me
     *
     * Returns the authenticated user's profile and their current subscription state.
     *
     * Requires: Authorization: Bearer {token}
     *
     * Response 200:
     * {
     *   "success": true,
     *   "message": "Success",
     *   "data": {
     *     "user":  { id, name, email, external_user_id, created_at },
     *     "subscription": { status, isActive, plan, remainingDays, expiryDate, ... }
     *   }
     * }
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'user'         => $this->formatUser($user),
            'subscription' => $this->subscriptionService->getSubscriptionStatus($user),
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** Shapes the raw service result into the standard auth response payload. */
    private function buildAuthPayload(array $result): array
    {
        return [
            'user'          => $this->formatUser($result['user']),
            'token'         => $result['token'],
            'token_type'    => 'Bearer',
            'subscription'  => $result['subscription'],
            'referral_code' => $result['referral_code'] ?? null,
        ];
    }

    /** Returns a consistent, minimal user object for API responses. */
    private function formatUser(User $user): array
    {
        return [
            'id'               => $user->id,
            'name'             => $user->name,
            'email'            => $user->email,
            'external_user_id' => $user->external_user_id,
            'created_at'       => $user->created_at?->toIso8601String(),
        ];
    }
}
