<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Referral\ApplyReferralRequest;
use App\Http\Requests\Referral\RequestPayoutRequest;
use App\Models\Commission;
use App\Models\CommissionPayout;
use App\Models\Referral;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ReferralController extends ApiController
{
    public function __construct(private readonly ReferralService $referralService) {}

    // =========================================================================
    // 1. GET /api/v1/referral/code
    // =========================================================================

    /**
     * Returns (or lazily generates) the authenticated user's referral code.
     *
     * Response 200:
     * {
     *   "referral_code": "USER1-XK9PLA",
     *   "share_url": "https://app.example.com/register?ref=USER1-XK9PLA",
     *   "stats": { total_referrals, total_earnings, pending_earnings, ... }
     * }
     */
    public function code(Request $request): JsonResponse
    {
        $user    = $request->user();
        $profile = $this->referralService->generateReferralCode($user);

        $shareUrl = rtrim(config('app.url'), '/') . '/register?ref=' . $profile->referral_code;

        return $this->success([
            'referral_code' => $profile->referral_code,
            'share_url'     => $shareUrl,
            'is_active'     => $profile->is_active,
            'stats'         => [
                'total_referrals'      => $profile->total_referrals,
                'total_earnings'       => $profile->total_earnings,
                'pending_earnings'     => $profile->pending_earnings,
                'total_paid_out'       => $profile->total_paid_out,
            ],
        ]);
    }

    // =========================================================================
    // 2. POST /api/v1/referral/apply
    // =========================================================================

    /**
     * Applies a referral code for the authenticated user.
     * Can be called at any point after registration (if user has not been referred yet).
     *
     * Request body: { referral_code: "USER1-XK9PLA" }
     */
    public function apply(ApplyReferralRequest $request): JsonResponse
    {
        $user = $request->user();

        try {
            $referral = $this->referralService->applyReferralCode(
                $user,
                $request->input('referral_code'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            [
                'referral_id'  => $referral->id,
                'referred_by'  => $referral->referrer_user_id,
                'status'       => $referral->status->value,
                'referred_at'  => $referral->referred_at->toIso8601String(),
            ],
            'Referral code applied successfully.',
        );
    }

    // =========================================================================
    // 3. GET /api/v1/referral/stats
    // =========================================================================

    /**
     * Returns a full summary of the user's referral activity.
     */
    public function stats(Request $request): JsonResponse
    {
        $stats = $this->referralService->getReferralStats($request->user());

        return $this->success($stats);
    }

    // =========================================================================
    // 4. GET /api/v1/referral/earnings
    // =========================================================================

    /**
     * Paginated commission (earnings) history for the authenticated user.
     * Query params: ?page=1&per_page=15
     */
    public function earnings(Request $request): JsonResponse
    {
        $user    = $request->user();
        $perPage = min((int) $request->query('per_page', 15), 50);

        $paginator = Commission::where('referrer_user_id', $user->id)
            ->with(['referred:id,name,email', 'referral:id,status', 'paymentOrder:id,amount,currency'])
            ->latest()
            ->paginate($perPage);

        return $this->success([
            'data' => collect($paginator->items())
                ->map(fn (Commission $c) => $this->formatCommission($c))
                ->values(),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    // =========================================================================
    // 5. GET /api/v1/referral/referrals
    // =========================================================================

    /**
     * Paginated list of users the authenticated user has referred,
     * enriched with their subscription status and commission earned.
     * Query params: ?page=1&per_page=15
     */
    public function referrals(Request $request): JsonResponse
    {
        $user    = $request->user();
        $perPage = min((int) $request->query('per_page', 15), 50);

        $paginator = Referral::where('referrer_user_id', $user->id)
            ->with([
                'referred:id,name,email,created_at',
                'commission:id,referral_id,amount,status,credited_at',
            ])
            ->latest('referred_at')
            ->paginate($perPage);

        return $this->success([
            'data' => collect($paginator->items())
                ->map(fn (Referral $r) => [
                    'id'             => $r->id,
                    'status'         => $r->status->value,
                    'statusLabel'    => $r->status->label(),
                    'referred_user'  => $r->referred ? [
                        'id'       => $r->referred->id,
                        'name'     => $r->referred->name,
                        'email'    => $r->referred->email,
                        'joined_at'=> $r->referred->created_at?->toDateString(),
                    ] : null,
                    'referred_at'    => $r->referred_at->toDateString(),
                    'completed_at'   => $r->completed_at?->toDateString(),
                    'commission'     => $r->commission ? [
                        'amount'      => $r->commission->amount,
                        'status'      => $r->commission->status->value,
                        'credited_at' => $r->commission->credited_at?->toDateString(),
                    ] : null,
                ])
                ->values(),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    // =========================================================================
    // 6. POST /api/v1/referral/payout/request
    // =========================================================================

    /**
     * Submits a payout request for the user's available commission balance.
     *
     * Request body:
     * {
     *   "payout_method": "bank_transfer",
     *   "payout_details": { "bank_name": "BCA", "account_number": "1234567", "account_name": "Budi" }
     * }
     */
    public function requestPayout(RequestPayoutRequest $request): JsonResponse
    {
        $user = $request->user();

        try {
            $payout = $this->referralService->requestPayout(
                $user,
                array_merge(
                    $request->input('payout_details', []),
                    ['method' => $request->input('payout_method')],
                ),
            );
        } catch (\InvalidArgumentException|\UnderflowException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            $this->formatPayout($payout),
            'Payout request submitted. An admin will process it shortly.',
            201,
        );
    }

    // =========================================================================
    // 7. GET /api/v1/referral/payout/history
    // =========================================================================

    /**
     * Paginated payout history for the authenticated user.
     * Query params: ?page=1&per_page=15
     */
    public function payoutHistory(Request $request): JsonResponse
    {
        $user    = $request->user();
        $perPage = min((int) $request->query('per_page', 15), 50);

        $paginator = CommissionPayout::where('user_id', $user->id)
            ->with('processedBy:id,name')
            ->latest()
            ->paginate($perPage);

        return $this->success([
            'data' => collect($paginator->items())
                ->map(fn (CommissionPayout $p) => $this->formatPayout($p))
                ->values(),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    // =========================================================================
    // 8. GET /api/v1/referral/settings  (public)
    // =========================================================================

    /**
     * Returns public-facing referral settings (commission rate, minimum payout).
     * Response is cached for 1 hour.
     */
    public function settings(): JsonResponse
    {
        $data = Cache::remember('referral_public_settings', 3600, function () {
            $all = $this->referralService->getCommissionSettings();

            return [
                'commission_percentage' => $all['commission_percentage'] ?? 10,
                'min_payout_amount'     => $all['min_payout_amount'] ?? 50000,
                'referral_bonus'        => $all['referral_bonus'] ?? 0,
            ];
        });

        return $this->success($data);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function formatCommission(Commission $commission): array
    {
        return [
            'id'                    => $commission->id,
            'amount'                => $commission->amount,
            'commission_percentage' => $commission->commission_percentage,
            'status'                => $commission->status->value,
            'statusLabel'           => $commission->status->label(),
            'referred_user'         => $commission->referred ? [
                'id'    => $commission->referred->id,
                'name'  => $commission->referred->name,
                'email' => $commission->referred->email,
            ] : null,
            'payment_order'         => $commission->paymentOrder ? [
                'id'       => $commission->paymentOrder->id,
                'amount'   => $commission->paymentOrder->amount,
                'currency' => $commission->paymentOrder->currency,
            ] : null,
            'credited_at'           => $commission->credited_at?->toIso8601String(),
            'paid_out_at'           => $commission->paid_out_at?->toIso8601String(),
            'created_at'            => $commission->created_at->toIso8601String(),
        ];
    }

    private function formatPayout(CommissionPayout $payout): array
    {
        return [
            'id'             => $payout->id,
            'amount'         => $payout->amount,
            'payout_method'  => $payout->payout_method,
            'status'         => $payout->status->value,
            'statusLabel'    => $payout->status->label(),
            'processed_by'   => $payout->processedBy?->name,
            'processed_at'   => $payout->processed_at?->toIso8601String(),
            'notes'          => $payout->notes,
            'created_at'     => $payout->created_at->toIso8601String(),
        ];
    }

    /** Builds a standard pagination meta block from a LengthAwarePaginator. */
    private function paginationMeta(\Illuminate\Pagination\LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'last_page'    => $paginator->lastPage(),
            'has_more'     => $paginator->hasMorePages(),
        ];
    }
}
