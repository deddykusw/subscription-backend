<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Payment\CreateOrderRequest;
use App\Http\Requests\Payment\SubmitProofRequest;
use App\Http\Requests\Payment\VerifyPaymentRequest;
use App\Mail\CommissionEarnedMail;
use App\Mail\PaymentRejectedMail;
use App\Mail\PaymentVerifiedMail;
use App\Models\Commission;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ReferralService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PaymentController extends ApiController
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly ReferralService     $referralService,
    ) {}

    // =========================================================================
    // Order creation
    // =========================================================================

    /**
     * POST /api/v1/subscription/order
     *
     * Creates a pending payment order for the authenticated user and returns
     * the PayPal payment instructions alongside the order record.
     *
     * Request body: { user_id, plan_id }
     * Response 201: { order, instructions }
     */
    public function createOrder(CreateOrderRequest $request): JsonResponse
    {
        $plan  = SubscriptionPlan::findOrFail($request->integer('plan_id'));
        $order = $this->subscriptionService->createPaymentOrder($request->user(), $plan);

        $order->load('plan');

        return $this->success([
            'order'        => $this->formatOrder($order),
            'instructions' => $this->buildPaymentInstructions($order),
        ], 'Payment order created. Please follow the instructions to complete payment.', 201);
    }

    // =========================================================================
    // Proof submission
    // =========================================================================

    /**
     * POST /api/v1/subscription/payment/proof
     *
     * Submits payment proof for a pending order.
     * Accepts either a file upload (`screenshot` field) or an external URL
     * (`screenshot_url`). The uploaded file is stored under the configured
     * proof disk; an external URL is stored as-is.
     *
     * Resubmission is allowed while the order is still pending (e.g. to
     * correct a wrong transaction ID).
     *
     * Request body: { order_id, transaction_id, paypal_email, screenshot?, screenshot_url? }
     */
    public function submitProof(SubmitProofRequest $request): JsonResponse
    {
        /** @var PaymentOrder $order */
        $order = PaymentOrder::findOrFail($request->integer('order_id'));

        $order->transaction_id = $request->input('transaction_id');
        $order->paypal_email   = $request->input('paypal_email');

        // Resolve screenshot: prefer file upload over external URL.
        if ($request->hasFile('screenshot') && $request->file('screenshot')->isValid()) {
            $order->proof_screenshot_url = $this->storeScreenshot(
                $request,
                $order->user_id,
            );
        } elseif ($request->filled('screenshot_url')) {
            $order->proof_screenshot_url = $request->input('screenshot_url');
        }

        $order->save();
        $order->load(['plan', 'user']);

        return $this->success(
            ['order' => $this->formatOrder($order)],
            'Payment proof submitted. Our team will verify your payment shortly.',
        );
    }

    // =========================================================================
    // Order queries
    // =========================================================================

    /**
     * GET /api/v1/subscription/order/{order}
     *
     * Returns the full details of a single payment order.
     * The authenticated user may only view their own orders unless they are admin.
     */
    public function orderDetails(PaymentOrder $order): JsonResponse
    {
        if ($error = $this->guardOrderAccess($order)) {
            return $error;
        }

        $order->loadMissing(['plan', 'user', 'subscription', 'verifier']);

        return $this->success(['order' => $this->formatOrder($order)]);
    }

    /**
     * GET /api/v1/subscription/payments/{user}
     *
     * Paginated payment history for a user.
     * Use ?per_page=N (max 50) and ?page=N for navigation.
     *
     * The authenticated user may only view their own history unless they are admin.
     */
    public function paymentHistory(User $user, Request $request): JsonResponse
    {
        if ($error = $this->guardSelfOrAdmin($user)) {
            return $error;
        }

        $perPage   = min((int) $request->query('per_page', 15), 50);
        $paginator = $user->paymentOrders()
            ->with(['plan', 'subscription'])
            ->latest()
            ->paginate($perPage);

        return $this->success([
            'data' => collect($paginator->items())
                ->map(fn (PaymentOrder $o) => $this->formatOrder($o))
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
    // Admin verification
    // =========================================================================

    /**
     * POST /api/v1/subscription/admin/verify/{order}
     *
     * Verifies or rejects a payment order.
     * - is_verified = true  → activates the subscription, sends verification email.
     * - is_verified = false → rejects the order, notes field required, sends rejection email.
     *
     * Protected by auth:sanctum + admin middleware.
     *
     * Request body: { is_verified: bool, notes?: string }
     */
    public function adminVerify(PaymentOrder $order, VerifyPaymentRequest $request): JsonResponse
    {
        $admin = $request->user();

        if ($request->boolean('is_verified')) {
            $order = $this->subscriptionService->verifyPayment($order, $admin);
            $order->loadMissing(['plan', 'user', 'subscription', 'verifier']);

            $this->sendMailSafely(
                fn () => Mail::to($order->user->email)
                    ->send(new PaymentVerifiedMail($order)),
                "PaymentVerifiedMail to user #{$order->user_id}",
            );

            // Notify the referrer if a commission was created (and possibly credited).
            $this->notifyReferrerIfCommissionExists($order);

            return $this->success(
                ['order' => $this->formatOrder($order)],
                'Payment verified. Subscription has been activated.',
            );
        }

        // Rejection path
        $order->reject($admin, $request->input('notes'));
        $order->loadMissing(['plan', 'user', 'subscription', 'verifier']);

        $this->sendMailSafely(
            fn () => Mail::to($order->user->email)
                ->send(new PaymentRejectedMail($order)),
            "PaymentRejectedMail to user #{$order->user_id}",
        );

        return $this->success(
            ['order' => $this->formatOrder($order)],
            'Payment rejected. The user has been notified.',
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Looks up the commission linked to this payment order and emails the
     * referrer. Safe to call after verifyPayment() — no-ops when the user
     * was not referred or the referrer has no email address.
     */
    private function notifyReferrerIfCommissionExists(PaymentOrder $order): void
    {
        $commission = Commission::where('payment_order_id', $order->id)
            ->with('referrer:id,name,email')
            ->first();

        if ($commission === null || $commission->referrer?->email === null) {
            return;
        }

        $this->sendMailSafely(
            fn () => Mail::to($commission->referrer->email)
                ->send(new CommissionEarnedMail($commission)),
            "CommissionEarnedMail to referrer #{$commission->referrer_user_id}",
        );
    }

    /**
     * Returns an error response when the authenticated user tries to access
     * a payment order that does not belong to them (unless they are admin).
     */
    private function guardOrderAccess(PaymentOrder $order): ?JsonResponse
    {
        $auth = auth()->user();

        if ($auth?->id !== $order->user_id && ! $auth?->is_admin) {
            return $this->error(
                'You are not authorised to access this payment order.',
                403,
            );
        }

        return null;
    }

    /**
     * Returns an error response when the authenticated user tries to access
     * another user's data and is not an admin.
     */
    private function guardSelfOrAdmin(User $user): ?JsonResponse
    {
        $auth = auth()->user();

        if ($auth?->id !== $user->id && ! $auth?->is_admin) {
            return $this->error(
                'You are not authorised to access this user\'s payment history.',
                403,
            );
        }

        return null;
    }

    /**
     * Stores the uploaded screenshot under {disk}/payment-proofs/{userId}/{uuid}.{ext}
     * and returns its public URL.
     */
    private function storeScreenshot(Request $request, int $userId): string
    {
        $disk = config('payment.proof.storage_disk', 'public');
        $dir  = config('payment.proof.storage_path', 'payment-proofs') . "/{$userId}";
        $ext  = $request->file('screenshot')->extension();
        $name = Str::uuid() . ".{$ext}";

        $path = $request->file('screenshot')->storeAs($dir, $name, $disk);

        return Storage::disk($disk)->url($path);
    }

    /**
     * Sends a mail and swallows any exception, logging the failure instead.
     * Keeps the API response clean even when the mail server is misconfigured.
     */
    private function sendMailSafely(callable $send, string $context): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::error("Failed to send {$context}: {$e->getMessage()}");
        }
    }

    /**
     * Builds a human-readable set of PayPal payment instructions for the mobile app.
     *
     * @return array<int, array{ step: int, instruction: string }>
     */
    /**
     * Builds a human-readable set of PayPal payment instructions for the mobile app.
     * Text is sourced from config/subscription.php so it can be customised per deployment.
     *
     * @return array<int, array{ step: int, instruction: string }>
     */
    private function buildPaymentInstructions(PaymentOrder $order): array
    {
        $email    = config('subscription.paypal_admin_email');
        $name     = config('subscription.paypal_admin_name');
        $amount   = number_format((float) $order->amount, 0, '.', ',');
        $currency = $order->currency;
        $orderId  = $order->id;

        // Resolve instruction text, interpolating runtime values into placeholders.
        $texts = config('subscription.payment_instructions');
        $replace = [
            '{currency}'  => $currency,
            '{amount}'    => $amount,
            '{email}'     => $email,
            '{order_id}'  => "#{$orderId}",
        ];
        $interpolate = fn (string $key) => str_replace(
            array_keys($replace),
            array_values($replace),
            $texts[$key] ?? '',
        );

        return [
            'paypal_receiver_email' => $email,
            'paypal_receiver_name'  => $name,
            'amount'                => $order->amount,
            'currency'              => $currency,
            'order_id'              => $orderId,
            'steps'                 => [
                ['step' => 1, 'instruction' => $interpolate('step_send')],
                ['step' => 2, 'instruction' => $interpolate('step_method')],
                ['step' => 3, 'instruction' => $interpolate('step_note')],
                ['step' => 4, 'instruction' => $interpolate('step_transaction')],
                ['step' => 5, 'instruction' => $interpolate('step_proof')],
            ],
        ];
    }

    /**
     * Serialises a PaymentOrder into the standard response shape.
     * Relations should be loaded before calling this method.
     */
    private function formatOrder(PaymentOrder $order): array
    {
        return [
            'id'                 => $order->id,
            'status'             => $order->status->value,
            'statusLabel'        => $order->status->label(),
            'isFinal'            => $order->status->isFinal(),
            'amount'             => $order->amount,
            'currency'           => $order->currency,
            'paymentMethod'      => $order->payment_method,
            'paypalEmail'        => $order->paypal_email,
            'transactionId'      => $order->transaction_id,
            'proofScreenshotUrl' => $order->proof_screenshot_url,
            'notes'              => $order->notes,
            'verifiedAt'         => $order->verified_at?->toIso8601String(),
            'createdAt'          => $order->created_at->toIso8601String(),
            'plan'               => $order->relationLoaded('plan') && $order->plan ? [
                'id'           => $order->plan->id,
                'name'         => $order->plan->name,
                'slug'         => $order->plan->slug,
                'price'        => $order->plan->price,
                'currency'     => $order->plan->currency,
                'duration_days'=> $order->plan->duration_days,
            ] : null,
            'subscription'       => $order->relationLoaded('subscription') && $order->subscription
                ? $this->formatLinkedSubscription($order->subscription)
                : null,
            'verifier'           => $order->relationLoaded('verifier') && $order->verifier ? [
                'id'   => $order->verifier->id,
                'name' => $order->verifier->name,
            ] : null,
        ];
    }

    /** Minimal subscription summary embedded inside an order response. */
    private function formatLinkedSubscription(Subscription $subscription): array
    {
        return [
            'id'           => $subscription->id,
            'status'       => $subscription->status->value,
            'startDate'    => $subscription->start_date->toDateString(),
            'endDate'      => $subscription->end_date->toDateString(),
            'remainingDays'=> $subscription->getRemainingDays(),
        ];
    }
}
