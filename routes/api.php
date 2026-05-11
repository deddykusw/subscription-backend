<?php

/**
 * API Routes — v1
 *
 * All routes are prefixed with /api (set in bootstrap/app.php) and /v1 (set below).
 * Full base URL: {APP_URL}/api/v1/...
 *
 * Middleware stack:
 *   auth:sanctum        — requires a valid Sanctum bearer token
 *   admin               — requires auth:sanctum + is_admin = true
 *   check.subscription  — requires auth:sanctum + active subscription (trial or paid)
 *                         Automatically bypasses: plans, order, trial/activate, payment/proof
 *
 * NOTE: Laravel 13 has no Kernel.php — middleware aliases are registered
 *       in bootstrap/app.php via $middleware->alias([...]).
 */

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExternalAuthController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\Admin\ReferralAdminController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\VoucherController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // =========================================================================
    // PUBLIC ROUTES
    // No authentication required (voucher redeem + history — user from attendance_token).
    // =========================================================================

    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login',    [AuthController::class, 'login']);
    });

    // Voucher — public; user is identified by attendance_token (body on redeem, query on history).
    Route::post('/voucher/redeem', [VoucherController::class, 'redeem']);
    Route::get('/voucher/history', [VoucherController::class, 'history']);

    // =========================================================================
    // PROTECTED ROUTES — require valid Sanctum token
    // =========================================================================

    Route::middleware('auth:sanctum')->group(function () {

        // ── Auth ──────────────────────────────────────────────────────────────

        Route::prefix('auth')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me',      [AuthController::class, 'me']);
        });

        Route::post('/auth/refresh-subscription', [ExternalAuthController::class, 'refreshSubscription']);

        // ── Subscription ──────────────────────────────────────────────────────
        // Routes for browsing subscription state, plans, and history.
        // These intentionally do NOT require check.subscription so that
        // expired users can still see their status and browse available plans.

        Route::prefix('subscription')->group(function () {

            // Status & plan browsing (any authenticated user)
            Route::get('status/{user}',    [SubscriptionController::class, 'status']);
            Route::get('me',               [SubscriptionController::class, 'me']);
            Route::get('check/{user}',     [SubscriptionController::class, 'check']);
            Route::get('plans',            [SubscriptionController::class, 'plans']);
            Route::get('plans/{plan}',     [SubscriptionController::class, 'plan']);
            Route::get('history/{user}',   [SubscriptionController::class, 'history']);

            // ── Trial ─────────────────────────────────────────────────────────
            // POST before GET: "activate" must resolve before {user} wildcard.

            Route::post('trial/activate',  [SubscriptionController::class, 'activateTrial']);
            Route::get('trial/{user}',     [SubscriptionController::class, 'trial']);

            // ── Payment orders ────────────────────────────────────────────────
            // POST routes registered before GET routes to avoid wildcard collisions.

            Route::post('order',           [PaymentController::class, 'createOrder']);
            Route::post('payment/proof',   [PaymentController::class, 'submitProof']);
            Route::get('order/{order}',    [PaymentController::class, 'orderDetails']);
            Route::get('payments/{user}',  [PaymentController::class, 'paymentHistory']);

            // ── Admin only ────────────────────────────────────────────────────
            // Stacked on top of auth:sanctum (already applied by the outer group).
            // Literal paths (admin/activate) must come before wildcard paths
            // (admin/verify/{order}) to avoid the segment being captured.

            Route::middleware('admin')->prefix('admin')->group(function () {
                Route::post('activate',        [SubscriptionController::class, 'adminActivate']);
                Route::post('verify/{order}',  [PaymentController::class, 'adminVerify']);
            });
        });

        // =========================================================================
        // FEATURE ROUTES — require auth + active subscription
        //
        // Apply the check.subscription middleware here when adding routes that sit
        // behind the subscription paywall (e.g., attendance data, reports, exports).
        // The middleware automatically bypasses /subscription/plans and
        // /subscription/order so expired users can still purchase a new plan.
        //
        // Example:
        //   Route::middleware('check.subscription')
        //        ->prefix('attendance')
        //        ->group(function () {
        //            Route::get('report', [AttendanceController::class, 'report']);
        //        });
        // =========================================================================

        // =========================================================================
        // REFERRAL ROUTES — require valid Sanctum token
        // =========================================================================

        Route::prefix('referral')->group(function () {

            // Public settings (cached) — no subscription requirement
            Route::get('settings', [ReferralController::class, 'settings']);

            // Authenticated user referral routes
            Route::get('code',           [ReferralController::class, 'code']);
            Route::post('apply',         [ReferralController::class, 'apply']);
            Route::get('stats',          [ReferralController::class, 'stats']);
            Route::get('earnings',       [ReferralController::class, 'earnings']);
            Route::get('referrals',      [ReferralController::class, 'referrals']);
            Route::post('payout/request', [ReferralController::class, 'requestPayout']);
            Route::get('payout/history', [ReferralController::class, 'payoutHistory']);
        });

        // =========================================================================
        // REFERRAL ADMIN ROUTES — require auth:sanctum + admin middleware
        // =========================================================================

        // =========================================================================
        // VOUCHER ADMIN ROUTES — require auth:sanctum + admin middleware
        // =========================================================================

        Route::middleware('admin')->prefix('subscription/admin')->group(function () {
            Route::post('voucher/bulk-generate',           [VoucherController::class, 'bulkGenerate']);
            Route::post('voucher',                         [VoucherController::class, 'store']);
            Route::get('vouchers',                         [VoucherController::class, 'index']);
            Route::get('voucher/{voucher}',                [VoucherController::class, 'show']);
            Route::post('voucher/{voucher}/toggle',        [VoucherController::class, 'toggle']);
        });

        Route::middleware('admin')->prefix('subscription/admin/referral')->group(function () {
            Route::get('statistics',                            [ReferralAdminController::class, 'statistics']);
            Route::get('commissions',                           [ReferralAdminController::class, 'commissions']);
            Route::post('commission/{commission}/credit',       [ReferralAdminController::class, 'creditCommission']);
            Route::post('commission/{commission}/cancel',       [ReferralAdminController::class, 'cancelCommission']);
            Route::get('payouts',                               [ReferralAdminController::class, 'payouts']);
            Route::post('payout/{payout}/process',              [ReferralAdminController::class, 'processPayout']);
            Route::get('settings',                              [ReferralAdminController::class, 'getSettings']);
            Route::put('settings',                              [ReferralAdminController::class, 'updateSettings']);
        });
    });

    // External Auth Exchange (public)
    Route::post('/auth/exchange-token', [ExternalAuthController::class, 'exchangeToken']);
    Route::get('/auth/profile-by-token', [ExternalAuthController::class, 'profileByToken']);
    Route::post('/auth/validate-access', [ExternalAuthController::class, 'validateAccess']);
});
