<?php

namespace App\Services;

use App\Enums\CommissionPayoutStatus;
use App\Enums\CommissionStatus;
use App\Enums\ReferralStatus;
use App\Models\Commission;
use App\Models\CommissionPayout;
use App\Models\PaymentOrder;
use App\Models\Referral;
use App\Models\ReferralSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserReferral;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReferralService
{
    /** Cache TTL for referral settings (seconds). */
    private const SETTINGS_CACHE_TTL = 3600;

    /** Cache key for referral settings. */
    private const SETTINGS_CACHE_KEY = 'referral_settings_all';

    // =========================================================================
    // 1. Generate Referral Code
    // =========================================================================

    /**
     * Creates a UserReferral profile with a unique referral code for the given user.
     * Format: USER{id}-{random6}   e.g. "USER42-XK9PLA"
     *
     * @param  User $user
     * @return UserReferral
     *
     * @throws \RuntimeException  When a unique code cannot be generated.
     */
    public function generateReferralCode(User $user): UserReferral
    {
        // Return existing profile if already created.
        $existing = UserReferral::where('user_id', $user->id)->first();
        if ($existing) {
            return $existing;
        }

        $code = $this->buildUniqueCode($user->id);

        return UserReferral::create([
            'user_id'          => $user->id,
            'referral_code'    => $code,
            'total_referrals'  => 0,
            'total_earnings'   => 0,
            'total_paid_out'   => 0,
            'pending_earnings' => 0,
            'is_active'        => true,
        ]);
    }

    // =========================================================================
    // 2. Apply Referral Code
    // =========================================================================

    /**
     * Validates and applies a referral code for a newly registered user.
     *
     * Guards:
     *   - Code must exist and belong to an active UserReferral profile.
     *   - User must not have been referred before.
     *   - User cannot refer themselves.
     *
     * @param  User   $user          The newly registered user.
     * @param  string $referralCode  The code entered during registration.
     * @return Referral
     *
     * @throws \InvalidArgumentException  On any validation failure.
     */
    public function applyReferralCode(User $user, string $referralCode): Referral
    {
        // 1. Resolve the referrer's profile.
        $referrerProfile = UserReferral::where('referral_code', $referralCode)
            ->where('is_active', true)
            ->first();

        if ($referrerProfile === null) {
            throw new \InvalidArgumentException('Invalid or inactive referral code.');
        }

        // 2. Prevent self-referral.
        if ($referrerProfile->user_id === $user->id) {
            throw new \InvalidArgumentException('You cannot use your own referral code.');
        }

        // 3. Ensure the user has not already been referred.
        $alreadyReferred = Referral::where('referred_user_id', $user->id)->exists();
        if ($alreadyReferred) {
            throw new \InvalidArgumentException('This user has already been referred by someone.');
        }

        return DB::transaction(function () use ($user, $referralCode, $referrerProfile): Referral {
            // Create the referral record.
            $referral = Referral::create([
                'referrer_user_id' => $referrerProfile->user_id,
                'referred_user_id' => $user->id,
                'referral_code'    => $referralCode,
                'status'           => ReferralStatus::Pending,
                'referred_at'      => Carbon::now(),
            ]);

            // Stamp the user's own record so we can look up the referral cheaply.
            $user->update([
                'referred_by'         => $referrerProfile->user_id,
                'referral_code_used'  => $referralCode,
            ]);

            return $referral;
        });
    }

    // =========================================================================
    // 3. Referral Stats
    // =========================================================================

    /**
     * Returns a comprehensive summary of a user's referral activity.
     *
     * @param  User  $user
     * @return array{
     *   referral_code: string|null,
     *   total_referrals: int,
     *   total_earnings: float,
     *   pending_earnings: float,
     *   total_paid_out: float,
     *   available_for_payout: float,
     *   referrals: array
     * }
     */
    public function getReferralStats(User $user): array
    {
        $profile = UserReferral::where('user_id', $user->id)->first();

        if ($profile === null) {
            return [
                'referral_code'        => null,
                'total_referrals'      => 0,
                'total_earnings'       => 0.0,
                'pending_earnings'     => 0.0,
                'total_paid_out'       => 0.0,
                'available_for_payout' => 0.0,
                'referrals'            => [],
            ];
        }

        $referrals = Referral::where('referrer_user_id', $user->id)
            ->with(['referred:id,name,email,created_at', 'commission:id,referral_id,amount,status,credited_at'])
            ->latest('referred_at')
            ->get()
            ->map(fn (Referral $r) => [
                'id'            => $r->id,
                'status'        => $r->status->value,
                'statusLabel'   => $r->status->label(),
                'referred_user' => $r->referred ? [
                    'id'         => $r->referred->id,
                    'name'       => $r->referred->name,
                    'email'      => $r->referred->email,
                    'joined_at'  => $r->referred->created_at?->toDateString(),
                ] : null,
                'referred_at'   => $r->referred_at->toDateString(),
                'completed_at'  => $r->completed_at?->toDateString(),
                'commission'    => $r->commission ? [
                    'amount'      => $r->commission->amount,
                    'status'      => $r->commission->status->value,
                    'credited_at' => $r->commission->credited_at?->toDateString(),
                ] : null,
            ])
            ->values()
            ->all();

        // available_for_payout = pending earnings minus any in-flight payout requests.
        $lockedAmount = CommissionPayout::where('user_id', $user->id)
            ->whereIn('status', [CommissionPayoutStatus::Pending, CommissionPayoutStatus::Processing])
            ->sum('amount');

        $available = max(0.0, $profile->pending_earnings - $lockedAmount);

        return [
            'referral_code'        => $profile->referral_code,
            'total_referrals'      => $profile->total_referrals,
            'total_earnings'       => $profile->total_earnings,
            'pending_earnings'     => $profile->pending_earnings,
            'total_paid_out'       => $profile->total_paid_out,
            'available_for_payout' => $available,
            'referrals'            => $referrals,
        ];
    }

    // =========================================================================
    // 4. Calculate Commission
    // =========================================================================

    /**
     * Determines the commission amount owed for a verified payment order.
     *
     * Returns 0.0 when:
     *   - The paying user was not referred by anyone.
     *   - The referrer has no active UserReferral profile.
     *
     * @param  PaymentOrder $order  A verified (paid) payment order.
     * @return float                Commission amount in the order's currency.
     */
    public function calculateCommission(PaymentOrder $order): float
    {
        $user = $order->user ?? User::find($order->user_id);

        if ($user === null || $user->referred_by === null) {
            return 0.0;
        }

        $referrerProfile = UserReferral::where('user_id', $user->referred_by)
            ->where('is_active', true)
            ->first();

        if ($referrerProfile === null) {
            return 0.0;
        }

        $percentage = (float) $this->getCommissionSettings()['commission_percentage'];

        return round((float) $order->amount * $percentage / 100, 2);
    }

    // =========================================================================
    // 5. Create Commission
    // =========================================================================

    /**
     * Creates a pending Commission record after a payment order is verified.
     *
     * Returns null when:
     *   - The user was not referred.
     *   - The referral relationship cannot be resolved.
     *   - A commission for this payment order already exists.
     *
     * @param  PaymentOrder  $order
     * @param  Subscription  $subscription  The subscription activated by the payment.
     * @return Commission|null
     */
    public function createCommission(PaymentOrder $order, Subscription $subscription): ?Commission
    {
        $user = $order->user ?? User::find($order->user_id);

        if ($user === null || $user->referred_by === null) {
            return null;
        }

        // Idempotency guard — never create two commissions for the same order.
        if (Commission::where('payment_order_id', $order->id)->exists()) {
            return null;
        }

        $referral = Referral::where('referrer_user_id', $user->referred_by)
            ->where('referred_user_id', $user->id)
            ->first();

        if ($referral === null) {
            return null;
        }

        $amount     = $this->calculateCommission($order);
        $percentage = (float) $this->getCommissionSettings()['commission_percentage'];

        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($order, $subscription, $referral, $amount, $percentage): Commission {
            return Commission::create([
                'referrer_user_id'      => $referral->referrer_user_id,
                'referred_user_id'      => $referral->referred_user_id,
                'referral_id'           => $referral->id,
                'subscription_id'       => $subscription->id,
                'payment_order_id'      => $order->id,
                'amount'                => $amount,
                'commission_percentage' => $percentage,
                'status'                => CommissionStatus::Pending,
            ]);
        });
    }

    // =========================================================================
    // 6. Credit Commission
    // =========================================================================

    /**
     * Credits a pending commission to the referrer's balance and marks the
     * associated referral as completed.
     *
     * @param  Commission $commission
     * @return Commission
     *
     * @throws \LogicException  When the commission is not in pending status.
     */
    public function creditCommission(Commission $commission): Commission
    {
        if ($commission->status !== CommissionStatus::Pending) {
            throw new \LogicException(
                "Cannot credit a commission with status '{$commission->status->label()}'."
            );
        }

        return DB::transaction(function () use ($commission): Commission {
            // Credit the commission and update referrer's balance.
            $commission->credit();

            // Mark the referral as completed.
            $referral = $commission->referral ?? Referral::find($commission->referral_id);
            $referral?->markCompleted();

            // Increment the referrer's total referral count (only once per referral).
            UserReferral::where('user_id', $commission->referrer_user_id)
                ->increment('total_referrals');

            return $commission->fresh();
        });
    }

    // =========================================================================
    // 7. Request Payout
    // =========================================================================

    /**
     * Creates a payout request for a user's available commission balance.
     *
     * @param  User  $user
     * @param  array $payoutDetails  e.g. ['bank_name' => 'BCA', 'account_number' => '1234567', 'account_name' => 'Budi']
     * @return CommissionPayout
     *
     * @throws \InvalidArgumentException  When balance is below minimum payout threshold.
     * @throws \UnderflowException        When there are no available earnings.
     */
    public function requestPayout(User $user, array $payoutDetails): CommissionPayout
    {
        $settings = $this->getCommissionSettings();
        $minPayout = (float) $settings['min_payout_amount'];

        $profile = UserReferral::where('user_id', $user->id)->first();

        if ($profile === null || $profile->pending_earnings <= 0) {
            throw new \UnderflowException('You have no pending earnings available for payout.');
        }

        // Deduct any already-locked payout requests.
        $lockedAmount = CommissionPayout::where('user_id', $user->id)
            ->whereIn('status', [CommissionPayoutStatus::Pending, CommissionPayoutStatus::Processing])
            ->sum('amount');

        $available = $profile->pending_earnings - $lockedAmount;

        if ($available < $minPayout) {
            throw new \InvalidArgumentException(
                "Minimum payout amount is IDR " . number_format($minPayout, 0, '.', ',') .
                ". Your available balance is IDR " . number_format($available, 0, '.', ',') . "."
            );
        }

        $method = $payoutDetails['method'] ?? $settings['payout_method'] ?? 'manual';

        return CommissionPayout::create([
            'user_id'        => $user->id,
            'amount'         => $available,
            'payout_method'  => $method,
            'payout_details' => $payoutDetails,
            'status'         => CommissionPayoutStatus::Pending,
        ]);
    }

    // =========================================================================
    // 8. Process Payout
    // =========================================================================

    /**
     * Admin action: approves or rejects a commission payout request.
     *
     * On success:
     *   - Status → completed, earnings deducted, commissions marked paid_out.
     *
     * On failure:
     *   - Status → failed, locked earnings released, notes recorded.
     *
     * @param  CommissionPayout $payout
     * @param  User             $admin    The admin performing the action.
     * @param  bool             $success  true = approve, false = reject.
     * @param  string|null      $notes    Required when rejecting.
     * @return CommissionPayout
     *
     * @throws \LogicException            When the payout is already in a final state.
     * @throws \InvalidArgumentException  When rejecting without providing notes.
     */
    public function processPayout(
        CommissionPayout $payout,
        User $admin,
        bool $success,
        ?string $notes = null,
    ): CommissionPayout {
        if ($payout->status->isFinal()) {
            throw new \LogicException(
                "Cannot process a payout with status '{$payout->status->label()}'."
            );
        }

        return DB::transaction(function () use ($payout, $admin, $success, $notes): CommissionPayout {
            if ($success) {
                $payout->markCompleted($admin);
            } else {
                if (empty($notes)) {
                    throw new \InvalidArgumentException('A rejection reason is required when failing a payout.');
                }
                $payout->markFailed($notes, $admin);
            }

            return $payout->fresh();
        });
    }

    // =========================================================================
    // 9. Get Commission Settings
    // =========================================================================

    /**
     * Returns all referral settings as a key→value array.
     * Result is cached for {@see SETTINGS_CACHE_TTL} seconds.
     *
     * @return array<string, mixed>
     */
    public function getCommissionSettings(): array
    {
        return Cache::remember(self::SETTINGS_CACHE_KEY, self::SETTINGS_CACHE_TTL, function (): array {
            return ReferralSetting::all()
                ->mapWithKeys(fn ($s) => [$s->key => $s->getValue()])
                ->all();
        });
    }

    // =========================================================================
    // 10. Update Commission Settings
    // =========================================================================

    /**
     * Bulk-updates referral settings and clears the settings cache.
     *
     * @param  array<string, mixed> $settings  Associative array of key → value pairs.
     * @return array<string, mixed>            The updated settings.
     */
    public function updateCommissionSettings(array $settings): array
    {
        DB::transaction(function () use ($settings): void {
            foreach ($settings as $key => $value) {
                ReferralSetting::set($key, $value);
            }
        });

        Cache::forget(self::SETTINGS_CACHE_KEY);

        return $this->getCommissionSettings();
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Generates a unique referral code in the format USER{id}-{RANDOM6}.
     * Retries up to 10 times before throwing an exception.
     *
     * @param  int $userId
     * @return string
     *
     * @throws \RuntimeException  When a unique code cannot be generated after max retries.
     */
    private function buildUniqueCode(int $userId): string
    {
        $maxAttempts = 10;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $code = 'USER' . $userId . '-' . strtoupper(Str::random(6));

            if (! UserReferral::where('referral_code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException(
            "Could not generate a unique referral code for user #{$userId} after {$maxAttempts} attempts."
        );
    }
}
