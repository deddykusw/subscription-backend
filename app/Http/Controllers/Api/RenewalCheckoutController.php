<?php

namespace App\Http\Controllers\Api;

use App\Enums\RenewalCheckoutStatus;
use App\Enums\RenewalPeriod;
use App\Exceptions\RenewalInProgressException;
use App\Http\Requests\Renewal\CreateRenewalCheckoutRequest;
use App\Http\Requests\Renewal\RenewalCancelRequest;
use App\Http\Requests\Renewal\RenewalListRequest;
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
     * GET /api/v1/renewals/list
     *
     * Query: attendance_token (required, via middleware), optional page, per_page, status.
     */
    public function list(RenewalListRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('subscription_user');
        $validated = $request->validated();

        $perPage = min((int) ($validated['per_page'] ?? 15), 50);

        $query = RenewalCheckout::query()
            ->where('user_id', $user->id)
            ->with(['plan:id,name,slug,price,currency,duration_days'])
            ->latest();

        $status = $validated['status'] ?? null;
        if (is_string($status) && $status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $paginator = $query->paginate($perPage);

        return $this->success([
            'items' => collect($paginator->items())
                ->map(fn (RenewalCheckout $c) => $this->formatListItem($c))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatListItem(RenewalCheckout $checkout): array
    {
        $checkout->loadMissing('plan');

        return [
            'checkout_id' => $checkout->id,
            'period' => $checkout->period->value,
            'amount' => number_format((float) $checkout->amount, 2, '.', ''),
            'currency' => $checkout->currency,
            'status' => $checkout->status->value,
            'status_label' => $checkout->status->label(),
            'upload_deadline_at' => $checkout->upload_deadline_at?->toIso8601String(),
            'submitted_at' => $checkout->payment_proof_submitted_at?->toIso8601String(),
            'reviewed_at' => $checkout->reviewed_at?->toIso8601String(),
            'rejection_reason' => $checkout->status === RenewalCheckoutStatus::Rejected
                ? $checkout->admin_notes
                : null,
            'has_proof' => $checkout->proof_path !== null,
            'plan' => $checkout->plan ? [
                'id' => $checkout->plan->id,
                'name' => $checkout->plan->name,
                'slug' => $checkout->plan->slug,
            ] : null,
            'created_at' => $checkout->created_at?->toIso8601String(),
        ];
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
        } catch (RenewalInProgressException $e) {
            return $this->error($e->getMessage(), 409);
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
     * POST /api/v1/renewals/{checkout}/cancel
     *
     * Body JSON atau form: attendance_token (wajib). Hanya jika status `pending_payment`
     * (belum mengunggah bukti / belum menunggu review admin).
     */
    public function cancel(RenewalCancelRequest $request, RenewalCheckout $checkout): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('subscription_user');
        $this->authorizeForUser($user, 'cancel', $checkout);

        try {
            $fresh = $this->renewalPaymentService->cancelCheckout($checkout, $user);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'checkout_id' => $fresh->id,
            'status' => $fresh->status->value,
        ], 'Checkout perpanjangan dibatalkan.');
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
            'reviewed_at' => $checkout->reviewed_at?->toIso8601String(),
            'rejection_reason' => $checkout->status === RenewalCheckoutStatus::Rejected
                ? $checkout->admin_notes
                : null,
        ]);
    }
}
