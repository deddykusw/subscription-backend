<?php

namespace App\Services;

use App\Enums\PaymentOrderStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\SubscriptionException;
use App\Models\Commission;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionService
{
    public function __construct(private readonly ReferralService $referralService) {}

    /** Returns the configured trial duration. Reads from config/subscription.php → trial_days. */
    private function trialDays(): int
    {
        return (int) config('subscription.trial_days', 7);
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Grants a free trial (duration from config: subscription.trial_days) to a new user.
     * Associates the trial with the cheapest active plan to satisfy the FK constraint.
     *
     * @throws SubscriptionException If the user already has or has had any subscription.
     * @throws ModelNotFoundException If no active plans exist.
     */
    public function createTrialSubscription(User $user): Subscription
    {
        // Any prior subscription (even expired) disqualifies the user from a trial.
        if ($user->subscriptions()->exists()) {
            throw SubscriptionException::trialAlreadyUsed();
        }

        // Associate the trial with the lowest-priced plan to satisfy the plan_id FK.
        // The plan is informational during trial; the actual plan is chosen at payment time.
        $plan = SubscriptionPlan::active()->orderBy('price')->first();

        if ($plan === null) {
            throw SubscriptionException::noActivePlansAvailable();
        }

        $today = Carbon::today();
        $trialEnd = $today->copy()->addDays($this->trialDays());

        return Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Trial,
            'trial_start_date' => $today,
            'trial_end_date' => $trialEnd,
            'start_date' => $today,
            'end_date' => $trialEnd,
            'auto_renew' => false,
        ]);
    }

    /**
     * Returns a structured summary of the user's subscription state.
     *
     * Status values:
     *  - 'trial'   : currently in a free trial period
     *  - 'active'  : paid subscription is valid
     *  - 'expired' : had a subscription but it has lapsed
     *  - 'none'    : never subscribed
     *
     * @return array{
     *   status: string,
     *   isActive: bool,
     *   plan: array<string,mixed>|null,
     *   trialStartDate: string|null,
     *   trialEndDate: string|null,
     *   startDate: string|null,
     *   endDate: string|null,
     *   remainingDays: int,
     *   expiryDate: string|null,
     * }
     */
    public function getSubscriptionStatus(User $user): array
    {
        $current = $user->getCurrentSubscription();

        if ($current !== null) {
            $current->loadMissing('plan');
            $isTrial = $current->status === SubscriptionStatus::Trial;

            return [
                'status' => $current->status->value,
                'isActive' => $current->status->isAccessible(),
                'plan' => $this->formatPlan($current->plan),
                'trialStartDate' => $isTrial ? $current->trial_start_date?->toDateString() : null,
                'trialEndDate' => $isTrial ? $current->trial_end_date?->toDateString() : null,
                'startDate' => $current->start_date->toDateString(),
                'endDate' => $current->end_date->toDateString(),
                'remainingDays' => $current->getRemainingDays(),
                'expiryDate' => $current->end_date->toDateString(),
            ];
        }

        // No current subscription — surface the most recently expired/cancelled one, if any.
        $latest = $user->subscriptions()->with('plan')->latest('end_date')->first();

        if ($latest !== null) {
            return [
                'status' => 'expired',
                'isActive' => false,
                'plan' => $this->formatPlan($latest->plan),
                'trialStartDate' => null,
                'trialEndDate' => null,
                'startDate' => $latest->start_date->toDateString(),
                'endDate' => $latest->end_date->toDateString(),
                'remainingDays' => 0,
                'expiryDate' => $latest->end_date->toDateString(),
            ];
        }

        return [
            'status' => 'none',
            'isActive' => false,
            'plan' => null,
            'trialStartDate' => null,
            'trialEndDate' => null,
            'startDate' => null,
            'endDate' => null,
            'remainingDays' => 0,
            'expiryDate' => null,
        ];
    }

    /**
     * Determines whether the user is entitled to access the application.
     *
     * @return array{ isValid: bool, reason?: string }
     */
    public function checkSubscriptionValidity(User $user): array
    {
        $subscription = $user->getCurrentSubscription();

        if ($subscription === null) {
            $hadAny = $user->subscriptions()->exists();

            return [
                'isValid' => false,
                'reason' => $hadAny
                    ? 'Your subscription has expired. Please renew to continue.'
                    : 'No subscription found. Start a free trial or purchase a plan.',
            ];
        }

        // getCurrentSubscription() already filters end_date >= today, but we
        // double-check with the model method to guard against clock skew.
        if ($subscription->isExpired()) {
            return ['isValid' => false, 'reason' => 'Subscription has expired.'];
        }

        if (! $subscription->status->isAccessible()) {
            return [
                'isValid' => false,
                'reason' => "Subscription status is \"{$subscription->status->label()}\".",
            ];
        }

        return ['isValid' => true];
    }

    /**
     * Creates a pending payment order for a given plan.
     *
     * @throws SubscriptionException If the plan is inactive or a pending order already exists.
     */
    public function createPaymentOrder(User $user, SubscriptionPlan $plan): PaymentOrder
    {
        if (! $plan->is_active) {
            throw SubscriptionException::planNotActive($plan);
        }

        // Prevent duplicate pending orders for the same user + plan combination.
        $existing = PaymentOrder::where('user_id', $user->id)
            ->where('plan_id', $plan->id)
            ->pending()
            ->first();

        if ($existing !== null) {
            throw SubscriptionException::pendingOrderExists($existing);
        }

        return PaymentOrder::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'subscription_id' => null,
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'payment_method' => 'paypal',
            'status' => PaymentOrderStatus::Pending,
        ]);
    }

    /**
     * Cancels any current active/trial subscriptions and creates a new paid
     * subscription for the given plan.
     *
     * This is a public method so it can also be called manually (e.g., in Tinker
     * or tests). In normal flow it is invoked via verifyPayment().
     */
    public function activateSubscription(User $user, SubscriptionPlan $plan): Subscription
    {
        return DB::transaction(fn () => $this->performActivation($user, $plan));
    }

    /**
     * Marks a pending payment order as verified and immediately activates the
     * user's subscription. Both actions run inside a single transaction — either
     * both succeed or neither is committed.
     *
     * @throws SubscriptionException If the order is not in 'pending' status.
     */
    public function verifyPayment(PaymentOrder $order, User $verifier): PaymentOrder
    {
        if ($order->status !== PaymentOrderStatus::Pending) {
            throw SubscriptionException::orderNotPending($order);
        }

        return DB::transaction(function () use ($order, $verifier) {
            // Eagerly load relations needed below to avoid extra queries inside the transaction.
            $order->loadMissing(['user', 'plan']);

            $order->verify($verifier);

            // performActivation() is called directly (no inner transaction) because
            // we are already inside a transaction here.
            $subscription = $this->performActivation($order->user, $order->plan);

            $order->subscription_id = $subscription->id;
            $order->save();

            // Create referral commission (inside transaction so it rolls back with the order).
            $this->handleReferralCommission($order, $subscription);

            return $order->load(['subscription', 'plan', 'user', 'verifier']);
        });
    }

    /**
     * Grants paid access for a manual renewal: extends an active paid subscription by the plan's
     * duration, or cancels trial/active and creates a new active subscription (same rules as voucher).
     */
    public function grantRenewalPeriod(User $user, SubscriptionPlan $plan): Subscription
    {
        $user->refresh();
        $current = $user->getCurrentSubscription();

        if ($current !== null && $current->status === SubscriptionStatus::Active) {
            $current->end_date = $current->end_date->copy()->addDays($plan->duration_days);
            $current->plan_id = $plan->id;
            $current->save();

            return $current->fresh();
        }

        return $this->performActivation($user, $plan);
    }

    /**
     * Cancels the subscription.
     * Idempotent: cancelling an already-cancelled subscription returns true.
     *
     * @throws SubscriptionException If the subscription is already expired.
     */
    public function cancelSubscription(Subscription $subscription): bool
    {
        if ($subscription->status === SubscriptionStatus::Cancelled) {
            return true;
        }

        if ($subscription->status === SubscriptionStatus::Expired) {
            throw SubscriptionException::cannotCancelExpired();
        }

        $subscription->status = SubscriptionStatus::Cancelled;

        return $subscription->save();
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Creates a referral commission for a verified payment order, then
     * auto-credits it when the `auto_credit_commission` setting is enabled.
     *
     * Called inside the verifyPayment() transaction so a failed commission
     * never leaves the order/subscription in a partially committed state.
     * Returns the Commission (possibly already credited) or null when no
     * referral relationship exists for this user.
     */
    private function handleReferralCommission(PaymentOrder $order, Subscription $subscription): ?Commission
    {
        $commission = $this->referralService->createCommission($order, $subscription);

        if ($commission === null) {
            return null;
        }

        $settings = $this->referralService->getCommissionSettings();

        if (! empty($settings['auto_credit_commission'])) {
            try {
                $this->referralService->creditCommission($commission);

                return $commission->fresh();
            } catch (\Throwable $e) {
                Log::warning("Auto-credit failed for commission #{$commission->id}: {$e->getMessage()}");
            }
        }

        return $commission;
    }

    /**
     * Core subscription activation logic.
     *
     * MUST be called inside an existing DB transaction — it does not open one
     * itself, so callers that need atomicity must wrap the call.
     */
    private function performActivation(User $user, SubscriptionPlan $plan): Subscription
    {
        // Bulk-update via query builder to avoid loading each model individually.
        // Uses ->value instead of the enum object because update() bypasses Eloquent casting.
        $user->subscriptions()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trial->value])
            ->update(['status' => SubscriptionStatus::Cancelled->value]);

        $today = Carbon::today();

        return Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'start_date' => $today,
            'end_date' => $today->copy()->addDays($plan->duration_days),
            'auto_renew' => false,
        ]);
    }

    /**
     * Serialises a SubscriptionPlan into a consistent array shape for API responses.
     */
    private function formatPlan(?SubscriptionPlan $plan): ?array
    {
        if ($plan === null) {
            return null;
        }

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'duration_days' => $plan->duration_days,
            'features' => $plan->features,
        ];
    }
}
