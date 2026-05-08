<?php

namespace App\Exceptions;

use App\Models\PaymentOrder;
use App\Models\SubscriptionPlan;
use RuntimeException;

class SubscriptionException extends RuntimeException
{
    public function __construct(string $message, private readonly int $statusCode = 422)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    // -------------------------------------------------------------------------
    // Named constructors — one per business rule violation
    // -------------------------------------------------------------------------

    public static function trialAlreadyUsed(): self
    {
        return new self('The free trial has already been used for this account.');
    }

    public static function noActivePlansAvailable(): self
    {
        return new self('No active subscription plans are available.', 503);
    }

    public static function planNotActive(SubscriptionPlan $plan): self
    {
        return new self("Subscription plan \"{$plan->name}\" is not available for purchase.");
    }

    public static function pendingOrderExists(PaymentOrder $order): self
    {
        return new self(
            "A pending payment order (#{$order->id}) already exists for this plan. "
            . 'Complete or cancel it before creating a new one.',
        );
    }

    public static function orderNotPending(PaymentOrder $order): self
    {
        return new self(
            "Payment order #{$order->id} cannot be verified — "
            . "current status: {$order->status->label()}.",
        );
    }

    public static function cannotCancelExpired(): self
    {
        return new self('An expired subscription cannot be cancelled.');
    }

    /** Thrown when proof is submitted for an order that is no longer in pending state. */
    public static function orderNotSubmittable(PaymentOrder $order): self
    {
        return new self(
            "Payment proof cannot be submitted — "
            . "order #{$order->id} is already {$order->status->label()}.",
        );
    }

    /** Thrown when an order does not belong to the authenticated user. */
    public static function orderAccessDenied(PaymentOrder $order): self
    {
        return new self(
            "You are not authorised to access payment order #{$order->id}.",
            403,
        );
    }
}
