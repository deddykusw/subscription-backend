<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CommissionPayoutStatus;
use App\Enums\CommissionStatus;
use App\Enums\PaymentOrderStatus;
use App\Enums\RenewalCheckoutStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\CommissionPayout;
use App\Models\PaymentOrder;
use App\Models\RenewalCheckout;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voucher;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'total_users' => User::count(),
                'active_subscriptions' => Subscription::where('status', SubscriptionStatus::Active)->count(),
                'trial_subscriptions' => Subscription::where('status', SubscriptionStatus::Trial)->count(),
                'pending_payments' => PaymentOrder::where('status', PaymentOrderStatus::Pending)->count(),
                'renewals_awaiting_review' => RenewalCheckout::where('status', RenewalCheckoutStatus::AwaitingReview)->count(),
                'total_vouchers' => Voucher::count(),
                'redeemed_vouchers' => Voucher::where('used_count', '>', 0)->count(),
                'pending_commissions' => Commission::where('status', CommissionStatus::Pending)->count(),
                'pending_payouts' => CommissionPayout::whereIn('status', [
                    CommissionPayoutStatus::Pending->value,
                    CommissionPayoutStatus::Processing->value,
                ])->count(),
            ],
        ]);
    }
}
