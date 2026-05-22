<?php

namespace App\Policies;

use App\Enums\RenewalCheckoutStatus;
use App\Models\RenewalCheckout;
use App\Models\User;

class RenewalCheckoutPolicy
{
    public function view(User $user, RenewalCheckout $checkout): bool
    {
        return (int) $checkout->user_id === (int) $user->id;
    }

    public function uploadPaymentProof(User $user, RenewalCheckout $checkout): bool
    {
        return (int) $checkout->user_id === (int) $user->id
            && $checkout->status === RenewalCheckoutStatus::PendingPayment;
    }

    public function cancel(User $user, RenewalCheckout $checkout): bool
    {
        return (int) $checkout->user_id === (int) $user->id
            && $checkout->status === RenewalCheckoutStatus::PendingPayment;
    }
}
