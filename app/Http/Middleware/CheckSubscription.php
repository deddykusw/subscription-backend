<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies that the authenticated user has an active subscription (trial OR paid).
 * Returns HTTP 403 when the subscription is absent or expired.
 *
 * Certain paths are unconditionally allowed so that expired users can
 * still view plans and create a new payment order:
 *
 *   GET  api/v1/subscription/plans
 *   GET  api/v1/subscription/plans/*
 *   POST api/v1/subscription/order
 *   POST api/v1/subscription/trial/activate
 *   POST api/v1/subscription/payment/proof
 *
 * Admins bypass the check entirely so they can manage all accounts
 * regardless of their own subscription status.
 *
 * Usage (apply to feature routes that require an active subscription):
 *
 *   Route::middleware(['auth:sanctum', 'check.subscription'])->group(function () {
 *       // routes that require active trial or paid subscription
 *   });
 *
 * Registration: bootstrap/app.php → $middleware->alias(['check.subscription' => CheckSubscription::class])
 *
 * NOTE: Laravel 13 has no Kernel.php — aliases are registered in bootstrap/app.php.
 */
class CheckSubscription
{
    /**
     * Paths that bypass the subscription check.
     * Supports the same wildcards as Illuminate\Http\Request::is().
     *
     * Keep this list minimal — only paths that must remain accessible
     * for users to purchase or restore a subscription.
     */
    private const BYPASS_PATHS = [
        'api/v1/subscription/plans',
        'api/v1/subscription/plans/*',
        'api/v1/subscription/order',
        'api/v1/subscription/trial/activate',
        'api/v1/subscription/payment/proof',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Let auth:sanctum handle unauthenticated requests upstream.
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        // Admins are never blocked — they manage accounts for all users.
        if ($user->is_admin) {
            return $next($request);
        }

        // Always allow access to the purchase / renewal flow.
        if ($request->is(...self::BYPASS_PATHS)) {
            return $next($request);
        }

        // Reject requests from users with no valid subscription.
        if (! $user->hasActiveSubscription() && ! $user->isOnTrial()) {
            return response()->json([
                'success' => false,
                'message' => 'An active subscription is required to access this resource.',
                'code'    => 'SUBSCRIPTION_REQUIRED',
                'links'   => [
                    'plans' => url('api/v1/subscription/plans'),
                    'order' => url('api/v1/subscription/order'),
                ],
            ], 403);
        }

        return $next($request);
    }
}
