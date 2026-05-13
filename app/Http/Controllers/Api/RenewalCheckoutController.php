<?php

namespace App\Http\Controllers\Api;

use App\Enums\RenewalPeriod;
use App\Http\Requests\Renewal\CreateRenewalCheckoutRequest;
use App\Http\Requests\Renewal\RenewalPaymentInfoRequest;
use App\Http\Requests\Renewal\RenewalPaymentProofRequest;
use App\Models\RenewalCheckout;
use App\Models\User;
use App\Services\RenewalPaymentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RenewalCheckoutController extends ApiController
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RenewalPaymentService $renewalPaymentService,
    ) {}

    /**
     * GET /api/v1/renewals/payment-info
     *
     * Query: attendance_token (required), period=month|year (required).
     */
    public function paymentInfo(RenewalPaymentInfoRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('subscription_user');
        $period = RenewalPeriod::from($request->validated('period'));

        $plan = $this->renewalPaymentService->findPlanForPeriod($period);
        if ($plan === null) {
            return $this->error('Paket perpanjangan untuk periode ini tidak tersedia.', 404);
        }

        return $this->success($this->renewalPaymentService->buildPaymentInfo($plan, $period));
    }

    /**
     * POST /api/v1/renewals/checkout
     *
     * Body JSON or form: attendance_token (required), period (required).
     */
    public function checkout(CreateRenewalCheckoutRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('subscription_user');
        $period = RenewalPeriod::from($request->validated('period'));

        try {
            $checkout = $this->renewalPaymentService->createCheckout($user, $period);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 404);
        }

        $amount = number_format((float) $checkout->amount, 2, '.', '');

        return $this->success([
            'checkout_id' => $checkout->id,
            'amount' => $amount,
            'currency' => $checkout->currency,
            'period' => $checkout->period->value,
            'upload_deadline_at' => $checkout->upload_deadline_at?->toIso8601String(),
            'payment_instructions' => $this->renewalPaymentService->buildCheckoutInstructionLines($checkout->id),
        ], 'Checkout perpanjangan dibuat. Silakan lakukan pembayaran dan unggah bukti sebelum batas waktu.', 201);
    }

    /**
     * POST /api/v1/renewals/{checkout}/payment-proof
     *
     * multipart/form-data: attendance_token, file (jpeg/png).
     */
    public function uploadProof(RenewalPaymentProofRequest $request, RenewalCheckout $checkout): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('subscription_user');
        $this->authorizeForUser($user, 'uploadPaymentProof', $checkout);

        try {
            $fresh = $this->renewalPaymentService->attachProofAndSubmit($checkout, $request->file('file'));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'checkout_id' => $fresh->id,
            'status' => $fresh->status->value,
            'submitted_at' => $fresh->payment_proof_submitted_at?->toIso8601String(),
        ], 'Bukti pembayaran berhasil diunggah. Menunggu verifikasi.');
    }

    /**
     * GET /api/v1/renewals/{checkout}
     *
     * Query: attendance_token (required).
     */
    public function show(Request $request, RenewalCheckout $checkout): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('subscription_user');
        $this->authorizeForUser($user, 'view', $checkout);

        return $this->success([
            'checkout_id' => $checkout->id,
            'status' => $checkout->status->value,
            'period' => $checkout->period->value,
            'amount' => number_format((float) $checkout->amount, 2, '.', ''),
            'currency' => $checkout->currency,
            'upload_deadline_at' => $checkout->upload_deadline_at?->toIso8601String(),
            'submitted_at' => $checkout->payment_proof_submitted_at?->toIso8601String(),
        ]);
    }
}
