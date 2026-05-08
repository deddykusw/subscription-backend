<?php

/**
 * Subscription system configuration.
 *
 * All values have sensible defaults so the application starts without
 * requiring additional environment setup. Override via .env for each
 * deployment environment.
 */

return [

    // =========================================================================
    // Trial
    // =========================================================================

    /*
     * Number of days a new user's free trial lasts.
     * end_date = registration_date + trial_days
     * On day 1 the countdown shows exactly this number.
     */
    'trial_days' => (int) env('TRIAL_DAYS', 7),

    // =========================================================================
    // PayPal (manual transfer)
    // =========================================================================

    /*
     * The PayPal email address where customers send payment.
     * Shown in the payment instructions returned by POST /subscription/order.
     */
    'paypal_admin_email' => env('PAYPAL_RECEIVER_EMAIL', 'payments@example.com'),

    /*
     * Display name shown alongside the PayPal email in payment instructions.
     */
    'paypal_admin_name' => env('PAYPAL_RECEIVER_NAME', 'Subscription Payments'),

    // =========================================================================
    // Payment instruction text
    // =========================================================================

    /*
     * Customisable instruction strings sent to the mobile app after an order
     * is created. The controller interpolates {amount}, {currency},
     * {email}, and {order_id} placeholders at runtime.
     *
     * Keep wording concise — these are rendered as a numbered list in the app.
     */
    'payment_instructions' => [
        'step_send'        => 'Open PayPal and send {currency} {amount} to: {email}',
        'step_method'      => 'Use "Friends & Family" to avoid extra transaction fees.',
        'step_note'        => 'Include your Order ID #{order_id} in the PayPal payment note.',
        'step_transaction' => 'Copy the Transaction ID from your PayPal receipt.',
        'step_proof'       => 'Submit proof via POST /api/v1/subscription/payment/proof with your Transaction ID (and optionally a screenshot).',
    ],

    // =========================================================================
    // Plan feature definitions
    // =========================================================================

    /*
     * Human-readable feature list shown in the mobile app paywall.
     * These are display strings; machine-readable flags live in the
     * subscription_plans.features JSON column (set by SubscriptionPlanSeeder).
     *
     * 'highlight' marks the plan that should receive visual emphasis (e.g. a
     * "Best value" badge).
     */
    'plans' => [
        'monthly' => [
            'label'     => 'Monthly',
            'highlight' => false,
            'features'  => [
                'Full access to all features',
                'Up to 2 registered devices',
                'Data export',
                'Email support',
            ],
        ],
        'yearly' => [
            'label'     => 'Yearly',
            'highlight' => true,
            'badge'     => 'Best Value — 2 months free',
            'features'  => [
                'Full access to all features',
                'Up to 5 registered devices',
                'Data export',
                'Priority support',
                'Annual billing discount',
            ],
        ],
    ],

    // =========================================================================
    // Default admin account (used by AdminUserSeeder)
    // =========================================================================

    /*
     * Credentials for the seeded administrator account.
     * Change these before running db:seed in any shared environment.
     * ADMIN_PASSWORD is write-only: it is hashed by the User model's
     * 'hashed' cast and never stored or exposed in plain text.
     */
    'admin' => [
        'external_user_id' => env('ADMIN_EXTERNAL_ID', 'ADMIN-001'),
        'name'             => env('ADMIN_NAME', 'System Administrator'),
        'email'            => env('ADMIN_EMAIL', 'admin@subscription.test'),
        'password'         => env('ADMIN_PASSWORD', 'admin123'),
    ],

];
