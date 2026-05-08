<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Subscription\ActivateTrialRequest;
use App\Http\Requests\Subscription\AdminActivateRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SubscriptionController extends ApiController
{
    public function __construct(private readonly SubscriptionService $subscriptionService) {}

    // =========================================================================
    // Status endpoints
    // =========================================================================

    /**
     * GET /api/v1/subscription/status/{user}
     *
     * Full subscription status for a specific user.
     * Includes trial countdown when applicable.
     * Callers can only retrieve their own data.
     */
    public function status(User $user): JsonResponse
    {
        if ($error = $this->guardSelf($user)) {
            return $error;
        }

        $status = $this->subscriptionService->getSubscriptionStatus($user);

        // Enrich with a human-readable trial countdown when user is on trial.
        if ($status['status'] === 'trial' && $status['remainingDays'] > 0) {
            $status['trialCountdown'] = $this->buildTrialCountdown($status['remainingDays']);
        }

        return $this->success($status);
    }

    /**
     * GET /api/v1/subscription/me
     *
     * Full status plus the last 10 subscription records for the
     * authenticated user. One call gives the app everything it needs
     * for the subscription management screen.
     */
    public function me(Request $request): JsonResponse
    {
        $user   = $request->user();
        $status = $this->subscriptionService->getSubscriptionStatus($user);

        if ($status['status'] === 'trial' && $status['remainingDays'] > 0) {
            $status['trialCountdown'] = $this->buildTrialCountdown($status['remainingDays']);
        }

        $history = $user->subscriptions()
            ->with('plan')
            ->latest('end_date')
            ->limit(10)
            ->get()
            ->map(fn (Subscription $s) => $this->formatSubscription($s))
            ->values();

        return $this->success([
            'current' => $status,
            'history' => $history,
        ]);
    }

    /**
     * GET /api/v1/subscription/check/{user}
     *
     * Lightweight validity check — returns a single boolean flag plus an
     * optional reason string. Designed for server-to-server calls from the
     * attendance server that only need a yes/no answer.
     */
    public function check(User $user): JsonResponse
    {
        if ($error = $this->guardSelf($user)) {
            return $error;
        }

        return $this->success(
            $this->subscriptionService->checkSubscriptionValidity($user),
        );
    }

    // =========================================================================
    // Trial endpoints
    // =========================================================================

    /**
     * POST /api/v1/subscription/trial/activate
     *
     * Activates the 7-day free trial for the authenticated user.
     * The user_id in the request body must match the authenticated user.
     * Returns the full trial details so the app can update its UI immediately.
     */
    public function activateTrial(ActivateTrialRequest $request): JsonResponse
    {
        $user = $request->user();

        $trial = $this->subscriptionService->createTrialSubscription($user);
        $trial->loadMissing('plan');

        return $this->success([
            'subscription'   => $this->formatSubscription($trial),
            'trialCountdown' => $this->buildTrialCountdown($trial->getRemainingDays()),
        ], 'Free trial activated successfully.', 201);
    }

    /**
     * GET /api/v1/subscription/trial/{user}
     *
     * Trial-specific status with countdown.
     * Returns whether the user has an active trial, an expired trial, or has
     * never started one — so the app knows which CTA to display.
     */
    public function trial(User $user): JsonResponse
    {
        if ($error = $this->guardSelf($user)) {
            return $error;
        }

        // Find the most recent subscription that originated as a trial,
        // regardless of whether it has since been upgraded or expired.
        $trialRecord = $user->subscriptions()
            ->whereNotNull('trial_start_date')
            ->latest('created_at')
            ->first();

        if ($trialRecord === null) {
            return $this->success([
                'hasTrialAvailable' => true,
                'isOnTrial'         => false,
                'hasUsedTrial'      => false,
                'trialStartDate'    => null,
                'trialEndDate'      => null,
                'remainingDays'     => 0,
                'trialCountdown'    => null,
                'status'            => 'never_started',
            ]);
        }

        $isActive       = $user->isOnTrial();
        $remainingDays  = $user->getRemainingTrialDays();

        return $this->success([
            'hasTrialAvailable' => false,
            'isOnTrial'         => $isActive,
            'hasUsedTrial'      => true,
            'trialStartDate'    => $trialRecord->trial_start_date?->toDateString(),
            'trialEndDate'      => $trialRecord->trial_end_date?->toDateString(),
            'remainingDays'     => $remainingDays,
            'trialCountdown'    => $isActive ? $this->buildTrialCountdown($remainingDays) : null,
            'status'            => $isActive ? 'active' : 'expired',
        ]);
    }

    // =========================================================================
    // Plan endpoints
    // =========================================================================

    /**
     * GET /api/v1/subscription/plans
     *
     * Lists all active subscription plans ordered by price ascending.
     * Includes full feature list and pricing so the mobile app can render
     * the paywall without a second request.
     */
    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::active()
            ->orderBy('price')
            ->get()
            ->map(fn (SubscriptionPlan $p) => $this->formatPlan($p))
            ->values();

        return $this->success($plans);
    }

    /**
     * GET /api/v1/subscription/plans/{plan}
     *
     * Details for a single plan. Returns 404 for inactive plans so that
     * decommissioned plans cannot be accessed even with a direct ID.
     */
    public function plan(SubscriptionPlan $plan): JsonResponse
    {
        if (! $plan->is_active) {
            return $this->error('The requested plan is no longer available.', 404);
        }

        return $this->success($this->formatPlan($plan));
    }

    // =========================================================================
    // History endpoint
    // =========================================================================

    /**
     * GET /api/v1/subscription/history/{user}
     *
     * Paginated subscription history for a user, newest first.
     * Use ?page=N to paginate; defaults to 15 records per page.
     */
    public function history(User $user, Request $request): JsonResponse
    {
        if ($error = $this->guardSelf($user)) {
            return $error;
        }

        $perPage = min((int) $request->query('per_page', 15), 50);

        $paginator = $user->subscriptions()
            ->with('plan')
            ->latest('end_date')
            ->paginate($perPage);

        return $this->success([
            'data' => collect($paginator->items())
                ->map(fn (Subscription $s) => $this->formatSubscription($s))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
                'has_more'     => $paginator->hasMorePages(),
            ],
        ]);
    }

    // =========================================================================
    // Admin endpoints
    // =========================================================================

    /**
     * POST /api/v1/subscription/admin/activate
     *
     * Manually activates a subscription for any user, bypassing the payment
     * flow entirely. Intended for admin onboarding, testing, or issue resolution.
     *
     * Protected by auth:sanctum + admin middleware.
     *
     * Request body: { user_id: int, plan_id: int }
     */
    public function adminActivate(AdminActivateRequest $request): JsonResponse
    {
        $user = User::findOrFail($request->integer('user_id'));
        $plan = SubscriptionPlan::findOrFail($request->integer('plan_id'));

        $subscription = $this->subscriptionService->activateSubscription($user, $plan);
        $subscription->loadMissing('plan');

        return $this->success(
            ['subscription' => $this->formatSubscription($subscription)],
            "Subscription manually activated for user #{$user->id}.",
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Returns an error response when the authenticated user tries to access
     * another user's data. Returns null when access is allowed.
     *
     * Extend this check with a role/admin flag when multi-role support is added.
     */
    private function guardSelf(User $user): ?JsonResponse
    {
        if (auth()->id() !== $user->id) {
            return $this->error(
                'You are not authorised to access this user\'s subscription data.',
                403,
            );
        }

        return null;
    }

    /**
     * Builds a human-readable trial countdown string.
     * Examples: "7 days remaining", "1 day remaining", "Expires today"
     */
    private function buildTrialCountdown(int $days): string
    {
        return match (true) {
            $days === 0 => 'Expires today',
            $days === 1 => '1 day remaining',
            default     => "{$days} days remaining",
        };
    }

    /**
     * Serialises a Subscription into the standard array shape used across
     * all endpoints in this controller.
     */
    private function formatSubscription(Subscription $subscription): array
    {
        $subscription->loadMissing('plan');

        return [
            'id'             => $subscription->id,
            'status'         => $subscription->status->value,
            'statusLabel'    => $subscription->status->label(),
            'isActive'       => $subscription->status->isAccessible(),
            'plan'           => $subscription->plan ? [
                'id'           => $subscription->plan->id,
                'name'         => $subscription->plan->name,
                'slug'         => $subscription->plan->slug,
                'price'        => $subscription->plan->price,
                'currency'     => $subscription->plan->currency,
                'duration_days'=> $subscription->plan->duration_days,
            ] : null,
            'trialStartDate' => $subscription->trial_start_date?->toDateString(),
            'trialEndDate'   => $subscription->trial_end_date?->toDateString(),
            'startDate'      => $subscription->start_date->toDateString(),
            'endDate'        => $subscription->end_date->toDateString(),
            'remainingDays'  => $subscription->getRemainingDays(),
            'autoRenew'      => $subscription->auto_renew,
            'createdAt'      => $subscription->created_at->toIso8601String(),
        ];
    }

    /**
     * Serialises a SubscriptionPlan into the standard array shape used
     * across plan listing and detail endpoints.
     */
    private function formatPlan(SubscriptionPlan $plan): array
    {
        return [
            'id'           => $plan->id,
            'name'         => $plan->name,
            'slug'         => $plan->slug,
            'price'        => $plan->price,
            'currency'     => $plan->currency,
            'duration_days'=> $plan->duration_days,
            'features'     => $plan->features,
            'is_active'    => $plan->is_active,
        ];
    }
}
