<?php

namespace App\Http\Controllers\Api;

use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\ExternalAuthService;
use App\Services\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoucherController extends ApiController
{
    public function __construct(
        private readonly VoucherService $voucherService,
        private readonly ExternalAuthService $externalAuthService,
    ) {}

    // =========================================================================
    // POST /api/v1/voucher/redeem
    // =========================================================================

    /**
     * Redeem a voucher code to activate or extend the user's subscription.
     *
     * **Public** — no Sanctum login. After sesi-aja succeeds, the user is loaded
     * via `attendance_profiles.attendance_token` → `user_id`.
     *
     * Request: { "code": "XXXX-YYYY-ZZZZ", "attendance_token": "..." }
     *
     * Response 200:
     * {
     *   "message": "Voucher berhasil digunakan ...",
     *   "voucher": { "code", "duration_days" },
     *   "redemption": { "id", "redeemed_at" },
     *   "subscription": { "status", "isActive", "remainingDays", ... }
     * }
     */
    public function redeem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'              => ['required', 'string', 'max:50'],
            'attendance_token'  => ['required', 'string'],
        ]);

        try {
            $user = $this->externalAuthService->resolveLocalUserFromAttendanceToken(
                $validated['attendance_token'],
            );
            $result = $this->voucherService->redeem($user, $validated['code']);

            return $this->success($result, $result['message']);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 401);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // =========================================================================
    // GET /api/v1/voucher/history
    // =========================================================================

    /**
     * Paginated redemption history for the user identified by attendance_token.
     *
     * **Public** — no Sanctum. Same resolution as redeem: sesi-aja then `attendance_profiles` lookup.
     *
     * Query: ?attendance_token=...&page=1&per_page=15
     *
     * Response data: `{ "items": [...], "meta": {...} }` (avoids nesting `data` twice).
     */
    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'attendance_token' => ['required', 'string'],
            'page'             => ['sometimes', 'integer', 'min:1'],
            'per_page'         => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $user = $this->externalAuthService->resolveLocalUserFromAttendanceToken(
                $validated['attendance_token'],
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 401);
        }

        $perPage = min((int) ($validated['per_page'] ?? 15), 50);

        $paginator = VoucherRedemption::where('user_id', $user->id)
            ->with(['voucher:id,code,duration_days', 'subscription:id,status,start_date,end_date'])
            ->latest('redeemed_at')
            ->paginate($perPage);

        return $this->success([
            'items' => collect($paginator->items())
                ->map(fn (VoucherRedemption $r) => [
                    'id'           => $r->id,
                    'redeemed_at'  => $r->redeemed_at->toIso8601String(),
                    'voucher'      => $r->voucher ? [
                        'code'          => $r->voucher->code,
                        'duration_days' => $r->voucher->duration_days,
                    ] : null,
                    'subscription' => $r->subscription ? [
                        'status'    => $r->subscription->status->value,
                        'startDate' => $r->subscription->start_date->toDateString(),
                        'endDate'   => $r->subscription->end_date->toDateString(),
                    ] : null,
                ])
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
    // POST /api/v1/subscription/admin/voucher  [ADMIN]
    // =========================================================================

    /**
     * Creates a new voucher.
     *
     * Request:
     * {
     *   "code":          "PROMO2026",   // optional — auto-generated (XXXX-XXXX-XXXX) when omitted
     *   "duration_days": 30,
     *   "plan_id":       null,          // null = cheapest active plan used on redemption
     *   "valid_from":    "2026-01-01",  // nullable
     *   "valid_until":   "2026-12-31",  // nullable
     *   "notes":         "Promo Ramadan 2026"
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'          => ['nullable', 'string', 'max:50'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'plan_id'       => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'valid_from'    => ['nullable', 'date'],
            'valid_until'   => ['nullable', 'date', 'after_or_equal:valid_from'],
            'notes'         => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $voucher = $this->voucherService->create($request->user(), $validated);
            $voucher->load('plan:id,name,slug');

            return $this->success(
                $this->formatVoucher($voucher),
                'Voucher berhasil dibuat.',
                201,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // =========================================================================
    // POST /api/v1/subscription/admin/voucher/bulk-generate  [ADMIN]
    // =========================================================================

    /**
     * Generates multiple single-use voucher codes in one request.
     *
     * Request:
     * {
     *   "quantity":      100,
     *   "duration_days": 30,
     *   "plan_id":       null,
     *   "valid_from":    "2026-01-01",
     *   "valid_until":   "2026-12-31",
     *   "notes":         "Batch Tokopedia Mei 2026"
     * }
     *
     * Response 201:
     * {
     *   "quantity":      100,
     *   "duration_days": 30,
     *   "valid_until":   "2026-12-31",
     *   "codes":         ["ABCD-1234-EFGH", ...]
     * }
     */
    public function bulkGenerate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quantity'      => ['required', 'integer', 'min:1', 'max:1000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'plan_id'       => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'valid_from'    => ['nullable', 'date'],
            'valid_until'   => ['nullable', 'date', 'after_or_equal:valid_from'],
            'notes'         => ['nullable', 'string', 'max:1000'],
        ]);

        $codes = $this->voucherService->bulkGenerate($request->user(), $validated);

        return $this->success([
            'quantity'      => count($codes),
            'duration_days' => $validated['duration_days'],
            'valid_until'   => $validated['valid_until'] ?? null,
            'codes'         => $codes,
        ], count($codes).' voucher berhasil di-generate.', 201);
    }

    // =========================================================================
    // GET /api/v1/subscription/admin/vouchers  [ADMIN]
    // =========================================================================

    /**
     * Paginated list of all vouchers.
     * Query params: ?page=1&per_page=20&is_active=1
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $query = Voucher::with('plan:id,name,slug')
            ->withCount('redemptions')
            ->latest();

        if ($request->has('is_active')) {
            $query->where('is_active', (bool) $request->query('is_active'));
        }

        $paginator = $query->paginate($perPage);

        return $this->success([
            'data' => collect($paginator->items())
                ->map(fn (Voucher $v) => $this->formatVoucher($v))
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
    // GET /api/v1/subscription/admin/voucher/{voucher}  [ADMIN]
    // =========================================================================

    /**
     * Voucher details including paginated redemption list.
     */
    public function show(Voucher $voucher, Request $request): JsonResponse
    {
        $perPage     = min((int) $request->query('per_page', 15), 50);
        $redemptions = $voucher->redemptions()
            ->with(['user:id,name,email', 'subscription:id,status,end_date'])
            ->latest('redeemed_at')
            ->paginate($perPage);

        return $this->success([
            'voucher'     => $this->formatVoucher($voucher->load('plan:id,name,slug')),
            'redemptions' => [
                'data' => collect($redemptions->items())
                    ->map(fn (VoucherRedemption $r) => [
                        'id'          => $r->id,
                        'redeemed_at' => $r->redeemed_at->toIso8601String(),
                        'user'        => $r->user ? [
                            'id'    => $r->user->id,
                            'name'  => $r->user->name,
                            'email' => $r->user->email,
                        ] : null,
                        'subscription_end' => $r->subscription?->end_date->toDateString(),
                    ])
                    ->values(),
                'meta' => [
                    'total'    => $redemptions->total(),
                    'has_more' => $redemptions->hasMorePages(),
                ],
            ],
        ]);
    }

    // =========================================================================
    // POST /api/v1/subscription/admin/voucher/{voucher}/toggle  [ADMIN]
    // =========================================================================

    /**
     * Toggles a voucher's active status on/off.
     */
    public function toggle(Voucher $voucher): JsonResponse
    {
        $voucher = $this->voucherService->toggle($voucher);
        $status  = $voucher->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return $this->success(
            $this->formatVoucher($voucher),
            "Voucher berhasil {$status}.",
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function formatVoucher(Voucher $voucher): array
    {
        return [
            'id'            => $voucher->id,
            'code'          => $voucher->code,
            'duration_days' => $voucher->duration_days,
            'is_active'     => $voucher->is_active,
            'is_usable'     => $voucher->isUsable(),
            'is_redeemed'   => $voucher->used_count > 0,
            'valid_from'    => $voucher->valid_from?->toDateString(),
            'valid_until'   => $voucher->valid_until?->toDateString(),
            'notes'         => $voucher->notes,
            'plan'          => $voucher->relationLoaded('plan') && $voucher->plan ? [
                'id'   => $voucher->plan->id,
                'name' => $voucher->plan->name,
                'slug' => $voucher->plan->slug,
            ] : null,
            'redemptions_count' => $voucher->redemptions_count ?? null,
            'created_at'    => $voucher->created_at->toDateString(),
        ];
    }
}
