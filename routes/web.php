<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminMessagesController;
use App\Http\Controllers\Admin\AdminPaymentController;
use App\Http\Controllers\Admin\AdminPushNotificationController;
use App\Http\Controllers\Admin\AdminReferralController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminVoucherController;
use Illuminate\Support\Facades\Route;

// ── Admin auth (public) ───────────────────────────────────────────────────────

Route::get('/admin/login', [AdminAuthController::class, 'showLogin'])->name('admin.login');
Route::post('/admin/login', [AdminAuthController::class, 'login'])->name('admin.login.post');
Route::post('/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

// ── Admin dashboard (requires web session auth + is_admin) ────────────────────

Route::middleware(['auth', 'admin.web'])->prefix('admin')->name('admin.')->group(function () {

    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

    // ── Users ─────────────────────────────────────────────────────────────────
    Route::prefix('users')->name('users')->group(function () {
        Route::get('/', [AdminUserController::class, 'index'])->name('');
        Route::get('/{user}', [AdminUserController::class, 'show'])->name('.show');
        Route::post('/{user}/toggle-admin', [AdminUserController::class, 'toggleAdmin'])->name('.toggle-admin');
        Route::post('/{user}/activate', [AdminUserController::class, 'activateSubscription'])->name('.activate');
        Route::delete('/{user}', [AdminUserController::class, 'destroy'])->name('.destroy');
    });

    // ── Push notifications (FCM) ─────────────────────────────────────────────
    Route::get('/push', [AdminPushNotificationController::class, 'create'])->name('push');
    Route::post('/push', [AdminPushNotificationController::class, 'store'])->name('push.store');

    // ── Messages (user ↔ admin) ─────────────────────────────────────────────
    Route::prefix('messages')->name('messages.')->group(function () {
        Route::get('/', [AdminMessagesController::class, 'index'])->name('index');
        Route::get('/{user}', [AdminMessagesController::class, 'show'])->name('show');
        Route::post('/{user}/reply', [AdminMessagesController::class, 'reply'])->name('reply');
    });

    // ── Vouchers ─────────────────────────────────────────────────────────────
    Route::prefix('vouchers')->name('vouchers')->group(function () {
        Route::get('/', [AdminVoucherController::class, 'index'])->name('');
        Route::post('/', [AdminVoucherController::class, 'store'])->name('.store');
        Route::post('/bulk-generate', [AdminVoucherController::class, 'bulkGenerate'])->name('.bulk');
        Route::get('/{voucher}', [AdminVoucherController::class, 'show'])->name('.show');
        Route::post('/{voucher}/toggle', [AdminVoucherController::class, 'toggle'])->name('.toggle');
        Route::delete('/{voucher}', [AdminVoucherController::class, 'destroy'])->name('.destroy');
    });

    // ── Payments ──────────────────────────────────────────────────────────────
    Route::prefix('payments')->name('payments')->group(function () {
        Route::get('/', [AdminPaymentController::class, 'index'])->name('');
        Route::post('/{order}/verify', [AdminPaymentController::class, 'verify'])->name('.verify');
    });

    // ── Referrals ─────────────────────────────────────────────────────────────
    Route::prefix('referrals')->name('referrals.')->group(function () {
        Route::get('/commissions', [AdminReferralController::class, 'commissions'])->name('commissions');
        Route::post('/commissions/{commission}/credit', [AdminReferralController::class, 'creditCommission'])->name('commissions.credit');
        Route::post('/commissions/{commission}/cancel', [AdminReferralController::class, 'cancelCommission'])->name('commissions.cancel');

        Route::get('/payouts', [AdminReferralController::class, 'payouts'])->name('payouts');
        Route::post('/payouts/{payout}/process', [AdminReferralController::class, 'processPayout'])->name('payouts.process');

        Route::get('/settings', [AdminReferralController::class, 'settings'])->name('settings');
        Route::put('/settings', [AdminReferralController::class, 'updateSettings'])->name('settings.update');
    });
});

Route::get('/', fn () => redirect()->route('admin.login'));
