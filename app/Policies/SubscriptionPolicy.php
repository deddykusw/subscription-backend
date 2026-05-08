<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;
use App\Enums\SubscriptionStatus;

/**
 * Authorization policy for Subscription model actions.
 *
 * Registered in AppServiceProvider::boot().
 * Auto-discovered by Laravel when the class follows the {Model}Policy convention.
 *
 * Usage in controllers:
 *   $this->authorize('view', $subscription);          // Gate check
 *   Gate::allows('update', $subscription);             // manual check
 *   $request->user()->can('delete', $subscription);   // User helper
 */
class SubscriptionPolicy
{
    /**
     * Admins bypass all individual checks via the before() hook.
     * This is called before any of the named methods below.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->is_admin ? true : null;
    }

    /**
     * Can the user list subscriptions across all users?
     *
     * Non-admins are denied here because the application always scopes
     * subscription queries to the authenticated user via the relationship
     * ($user->subscriptions()). A general Subscription::all() query should
     * only be performed by admins.
     */
    public function viewAny(User $user): bool
    {
        return false; // non-admins use $user->subscriptions() instead
    }

    /**
     * Can the user view this specific subscription?
     * Users may only view subscriptions that belong to their own account.
     */
    public function view(User $user, Subscription $subscription): bool
    {
        return $user->id === $subscription->user_id;
    }

    /**
     * Can the user modify this subscription's mutable fields?
     * Currently the only user-facing mutable field is auto_renew.
     * Status changes and date changes are reserved for the service layer.
     */
    public function update(User $user, Subscription $subscription): bool
    {
        return $user->id === $subscription->user_id
            && $subscription->status->isAccessible();
    }

    /**
     * Can the user cancel (soft-delete) this subscription?
     * Users may cancel their own subscription as long as it is not already
     * in a terminal state (expired or cancelled).
     */
    public function delete(User $user, Subscription $subscription): bool
    {
        return $user->id === $subscription->user_id
            && ! in_array($subscription->status, [
                SubscriptionStatus::Expired,
                SubscriptionStatus::Cancelled,
            ], strict: true);
    }
}
