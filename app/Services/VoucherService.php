<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class VoucherService
{
    public function __construct(private readonly SubscriptionService $subscriptionService) {}

    // =========================================================================
    // Redeem a voucher
    // =========================================================================

    /**
     * Validates and redeems a voucher code for a user.
     *
     * Redemption rules:
     *   - Code must exist and be currently usable (active, within validity window, quota not exhausted)
     *   - User cannot redeem the same voucher twice
     *   - If user has an active paid subscription → extend end_date by duration_days
     *   - Otherwise → cancel any current trial/subscription and create a new active one
     *
     * @throws \InvalidArgumentException  On any validation failure.
     */
    public function redeem(User $user, string $code): array
    {
        $code    = strtoupper(trim($code));
        $voucher = Voucher::where('code', $code)->first();

        if ($voucher === null) {
            throw new \InvalidArgumentException('Kode voucher tidak ditemukan.');
        }

        $reason = $voucher->unusableReason();
        if ($reason !== null) {
            throw new \InvalidArgumentException($reason);
        }

        if (VoucherRedemption::where('voucher_id', $voucher->id)->where('user_id', $user->id)->exists()) {
            throw new \InvalidArgumentException('Anda sudah pernah menggunakan voucher ini.');
        }

        return DB::transaction(function () use ($user, $voucher): array {
            $subscription = $this->applyVoucher($user, $voucher);

            $redemption = VoucherRedemption::create([
                'voucher_id'      => $voucher->id,
                'user_id'         => $user->id,
                'subscription_id' => $subscription->id,
                'redeemed_at'     => Carbon::now(),
            ]);

            $voucher->increment('used_count');

            return [
                'message'      => "Voucher berhasil digunakan. Langganan Anda diperpanjang {$voucher->duration_days} hari.",
                'voucher'      => [
                    'code'          => $voucher->code,
                    'duration_days' => $voucher->duration_days,
                ],
                'redemption'   => [
                    'id'          => $redemption->id,
                    'redeemed_at' => $redemption->redeemed_at->toIso8601String(),
                ],
                'subscription' => $this->subscriptionService->getSubscriptionStatus($user->fresh()),
            ];
        });
    }

    // =========================================================================
    // Admin — create voucher
    // =========================================================================

    /**
     * Creates a new voucher. Code is auto-generated when not provided.
     *
     * @param  array{
     *   code?: string,
     *   duration_days: int,
     *   plan_id?: int|null,
     *   max_uses?: int|null,
     *   valid_from?: string|null,
     *   valid_until?: string|null,
     *   notes?: string|null,
     * } $data
     */
    public function create(User $admin, array $data): Voucher
    {
        $code = isset($data['code']) && $data['code'] !== ''
            ? strtoupper(trim($data['code']))
            : Voucher::generateCode();

        if (Voucher::where('code', $code)->exists()) {
            throw new \InvalidArgumentException("Kode voucher '{$code}' sudah digunakan.");
        }

        return Voucher::create([
            'code'          => $code,
            'duration_days' => $data['duration_days'],
            'plan_id'       => $data['plan_id']    ?? null,
            'max_uses'      => $data['max_uses']   ?? null,
            'valid_from'    => isset($data['valid_from'])  ? Carbon::parse($data['valid_from'])  : null,
            'valid_until'   => isset($data['valid_until']) ? Carbon::parse($data['valid_until']) : null,
            'notes'         => $data['notes']      ?? null,
            'is_active'     => true,
            'used_count'    => 0,
            'created_by'    => $admin->id,
        ]);
    }

    // =========================================================================
    // Admin — toggle active status
    // =========================================================================

    public function toggle(Voucher $voucher): Voucher
    {
        $voucher->is_active = ! $voucher->is_active;
        $voucher->save();

        return $voucher;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Applies the voucher to the user's subscription:
     *   - Active paid subscription → extend end_date
     *   - No active subscription / trial → cancel and create new active subscription
     */
    private function applyVoucher(User $user, Voucher $voucher): Subscription
    {
        $current = $user->getCurrentSubscription();

        // Extend existing active (paid) subscription.
        if ($current !== null && $current->status === SubscriptionStatus::Active) {
            $current->end_date = $current->end_date->addDays($voucher->duration_days);
            $current->save();

            return $current;
        }

        // Cancel any trial / other active subscription, then create a new one.
        $user->subscriptions()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trial->value])
            ->update(['status' => SubscriptionStatus::Cancelled->value]);

        $plan  = $voucher->plan ?? SubscriptionPlan::active()->orderBy('price')->firstOrFail();
        $today = Carbon::today();

        return Subscription::create([
            'user_id'    => $user->id,
            'plan_id'    => $plan->id,
            'status'     => SubscriptionStatus::Active,
            'start_date' => $today,
            'end_date'   => $today->copy()->addDays($voucher->duration_days),
            'auto_renew' => false,
        ]);
    }
}
