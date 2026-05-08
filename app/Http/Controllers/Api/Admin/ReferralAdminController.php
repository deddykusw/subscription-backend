<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Admin\Referral\CancelCommissionRequest;
use App\Http\Requests\Admin\Referral\ProcessPayoutRequest;
use App\Http\Requests\Admin\Referral\UpdateSettingsRequest;
use App\Models\Commission;
use App\Models\CommissionPayout;
use App\Models\Referral;
use App\Models\UserReferral;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralAdminController extends ApiController
{
    public function __construct(private readonly ReferralService $referralService) {}

    // =========================================================================
    // 1. GET /api/v1/subscription/admin/referral/statistics
    // =========================================================================

    /**
     * System-wide referral statistics dashboard.
     *
     * Response includes:
     *   - total_referrals (all time)
     *   - total_commissions_paid (IDR)
     *   - conversion_rate (% of referred users who completed a payment)
     *   - top_referrers (top 10 by earnings)
     */
    public function statistics(): JsonResponse
    {
        $totalReferrals  = Referral::count();
        $totalCompleted  = Referral::where('status', 'completed')->count();
        $totalPending    = Referral::where('status', 'pending')->count();
        $totalCancelled  = Referral::where('status', 'cancelled')->count();

        $totalCommissions = Commission::where('status', '!=', 'cancelled')->sum('amount');
        $totalPaidOut     = Commission::where('status', 'paid_out')->sum('amount');
        $totalPending     = Commission::where('status', 'pending')->sum('amount');

        $conversionRate = $totalReferrals > 0
            ? round(($totalCompleted / $totalReferrals) * 100, 2)
            : 0.0;

        $topReferrers = UserReferral::with('user:id,name,email')
            ->where('total_referrals', '>', 0)
            ->orderByDesc('total_earnings')
            ->limit(10)
            ->get()
            ->map(fn (UserReferral $ur) => [
                'user'             => $ur->user ? [
                    'id'    => $ur->user->id,
                    'name'  => $ur->user->name,
                    'email' => $ur->user->email,
                ] : null,
                'referral_code'    => $ur->referral_code,
                'total_referrals'  => $ur->total_referrals,
                'total_earnings'   => $ur->total_earnings,
                'pending_earnings' => $ur->pending_earnings,
                'total_paid_out'   => $ur->total_paid_out,
            ])
            ->values();

        return $this->success([
            'referrals' => [
                'total'      => $totalReferrals,
                'completed'  => $totalCompleted,
                'pending'    => $totalPending,
                'cancelled'  => $totalCancelled,
            ],
            'commissions' => [
                'total_amount'  => $totalCommissions,
                'total_paid'    => $totalPaidOut,
                'total_pending' => $totalPending,
            ],
            'conversion_rate' => $conversionRate,
            'top_referrers'   => $topReferrers,
        ]);
    }

    // =========================================================================
    // 2. GET /api/v1/subscription/admin/referral/commissions
    // =========================================================================

    /**
     * Paginated list of all commissions across the system.
     *
     * Query params:
     *   ?status=pending|credited|paid_out|cancelled
     *   ?user_id=123
     *   ?from=2026-01-01&to=2026-12-31
     *   ?page=1&per_page=15
     */
    public function commissions(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 15), 50);

        $query = Commission::with([
            'referrer:id,name,email',
            'referred:id,name,email',
            'referral:id,status',
            'paymentOrder:id,amount,currency',
        ])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('user_id')) {
            $query->where('referrer_user_id', $request->query('user_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->query('to'));
        }

        $paginator = $query->paginate($perPage);

        return $this->success([
            'data' => collect($paginator->items())
                ->map(fn (Commission $c) => $this->formatCommission($c))
                ->values(),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    // =========================================================================
    // 3. POST /api/v1/subscription/admin/referral/commission/{commission}/credit
    // =========================================================================

    /**
     * Manually credits a pending commission to the referrer's balance.
     */
    public function creditCommission(Commission $commission): JsonResponse
    {
        try {
            $updated = $this->referralService->creditCommission($commission);
        } catch (\LogicException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            $this->formatCommission($updated->load(['referrer', 'referred', 'referral', 'paymentOrder'])),
            'Commission credited successfully.',
        );
    }

    // =========================================================================
    // 4. POST /api/v1/subscription/admin/referral/commission/{commission}/cancel
    // =========================================================================

    /**
     * Cancels a commission and records the reason.
     *
     * Request body: { reason: "..." }
     */
    public function cancelCommission(Commission $commission, CancelCommissionRequest $request): JsonResponse
    {
        try {
            $commission->cancel($request->input('reason'));
        } catch (\LogicException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            $this->formatCommission($commission->fresh(['referrer', 'referred', 'referral', 'paymentOrder'])),
            'Commission cancelled.',
        );
    }

    // =========================================================================
    // 5. GET /api/v1/subscription/admin/referral/payouts
    // =========================================================================

    /**
     * Paginated list of all payout requests across the system.
     *
     * Query params:
     *   ?status=pending|processing|completed|failed
     *   ?user_id=123
     *   ?from=2026-01-01&to=2026-12-31
     *   ?page=1&per_page=15
     */
    public function payouts(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 15), 50);

        $query = CommissionPayout::with([
            'user:id,name,email',
            'processedBy:id,name',
        ])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->query('to'));
        }

        $paginator = $query->paginate($perPage);

        return $this->success([
            'data' => collect($paginator->items())
                ->map(fn (CommissionPayout $p) => $this->formatPayout($p))
                ->values(),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    // =========================================================================
    // 6. POST /api/v1/subscription/admin/referral/payout/{payout}/process
    // =========================================================================

    /**
     * Approves or rejects a commission payout request.
     *
     * Request body: { success: true|false, notes?: "..." }
     */
    public function processPayout(CommissionPayout $payout, ProcessPayoutRequest $request): JsonResponse
    {
        try {
            $updated = $this->referralService->processPayout(
                $payout,
                $request->user(),
                $request->boolean('success'),
                $request->input('notes'),
            );
        } catch (\LogicException|\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $message = $request->boolean('success')
            ? 'Payout completed successfully.'
            : 'Payout marked as failed.';

        return $this->success(
            $this->formatPayout($updated->load(['user', 'processedBy'])),
            $message,
        );
    }

    // =========================================================================
    // 7. GET /api/v1/subscription/admin/referral/settings
    // =========================================================================

    /**
     * Returns all referral settings (admin view — includes descriptions and types).
     */
    public function getSettings(): JsonResponse
    {
        $settings = \App\Models\ReferralSetting::all()
            ->map(fn ($s) => [
                'key'         => $s->key,
                'value'       => $s->getValue(),
                'description' => $s->description,
                'type'        => $s->type->value,
            ])
            ->keyBy('key')
            ->values();

        return $this->success($settings);
    }

    // =========================================================================
    // 8. PUT /api/v1/subscription/admin/referral/settings
    // =========================================================================

    /**
     * Bulk-updates referral settings and clears the cache.
     *
     * Request body: { commission_percentage: 15, min_payout_amount: 100000, ... }
     */
    public function updateSettings(UpdateSettingsRequest $request): JsonResponse
    {
        $updated = $this->referralService->updateCommissionSettings($request->validated());

        return $this->success($updated, 'Referral settings updated successfully.');
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
            'referrer'              => $commission->referrer ? [
                'id'    => $commission->referrer->id,
                'name'  => $commission->referrer->name,
                'email' => $commission->referrer->email,
            ] : null,
            'referred'              => $commission->referred ? [
                'id'    => $commission->referred->id,
                'name'  => $commission->referred->name,
                'email' => $commission->referred->email,
            ] : null,
            'payment_order'         => $commission->paymentOrder ? [
                'id'       => $commission->paymentOrder->id,
                'amount'   => $commission->paymentOrder->amount,
                'currency' => $commission->paymentOrder->currency,
            ] : null,
            'notes'                 => $commission->notes,
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
            'payout_details' => $payout->payout_details,
            'status'         => $payout->status->value,
            'statusLabel'    => $payout->status->label(),
            'user'           => $payout->user ? [
                'id'    => $payout->user->id,
                'name'  => $payout->user->name,
                'email' => $payout->user->email,
            ] : null,
            'processed_by'   => $payout->processedBy ? [
                'id'   => $payout->processedBy->id,
                'name' => $payout->processedBy->name,
            ] : null,
            'processed_at'   => $payout->processed_at?->toIso8601String(),
            'notes'          => $payout->notes,
            'created_at'     => $payout->created_at->toIso8601String(),
        ];
    }

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
