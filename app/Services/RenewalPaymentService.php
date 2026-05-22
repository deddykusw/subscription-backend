<?php

namespace App\Services;

use App\Enums\RenewalCheckoutStatus;
use App\Enums\RenewalPeriod;
use App\Exceptions\RenewalInProgressException;
use App\Models\RenewalCheckout;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RenewalPaymentService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {}

    public function findPlanForPeriod(RenewalPeriod $period): ?SubscriptionPlan
    {
        return SubscriptionPlan::query()
            ->active()
            ->where('slug', $period->planSlug())
            ->first();
    }

    /**
     * @return array{
     *   period: string,
     *   title: string,
     *   summary: string,
     *   amount: string,
     *   currency: string,
     *   payment_instructions: array<int, string>,
     *   server_time: string,
     * }
     */
    public function buildPaymentInfo(SubscriptionPlan $plan, RenewalPeriod $period): array
    {
        $amount = number_format((float) $plan->price, 2, '.', '');
        $periodLabel = $period === RenewalPeriod::Month ? 'monthly (1 month)' : 'yearly (12 months)';

        $lines = array_map(function (string $line) use ($amount, $plan, $periodLabel) {
            return str_replace(
                ['{amount}', '{currency}', '{period_label}'],
                [$amount, $plan->currency, $periodLabel],
                $line,
            );
        }, config('renewals.payment_info_instructions', []));

        return [
            'period' => $period->value,
            'title' => 'Renew '.$plan->name,
            'summary' => $plan->name.' — '.$plan->duration_days.' days access after verification.',
            'amount' => $amount,
            'currency' => $plan->currency,
            'payment_instructions' => array_values($lines),
            'server_time' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function buildCheckoutInstructionLines(string $checkoutId): array
    {
        $replace = ['{checkout_id}' => $checkoutId];

        return array_values(array_map(
            fn (string $line) => str_replace(array_keys($replace), array_values($replace), $line),
            config('renewals.checkout_instructions', []),
        ));
    }

    public function createCheckout(User $user, RenewalPeriod $period): RenewalCheckout
    {
        return DB::transaction(function () use ($user, $period) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (RenewalCheckout::query()->where('user_id', $user->id)->inProgress()->exists()) {
                throw new RenewalInProgressException;
            }

            $plan = $this->findPlanForPeriod($period);
            if ($plan === null) {
                throw new \InvalidArgumentException('The selected renewal period is not available.');
            }

            $deadlineHours = (int) config('renewals.upload_deadline_hours', 48);

            return RenewalCheckout::create([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'period' => $period,
                'status' => RenewalCheckoutStatus::PendingPayment,
                'amount' => $plan->price,
                'currency' => $plan->currency,
                'upload_deadline_at' => now()->addHours($deadlineHours),
            ]);
        });
    }

    /**
     * Stores proof on private disk and moves checkout to awaiting_review.
     *
     * @throws \RuntimeException When checkout is not in pending_payment state.
     */
    public function attachProofAndSubmit(RenewalCheckout $checkout, UploadedFile $file): RenewalCheckout
    {
        return DB::transaction(function () use ($checkout, $file) {
            $checkout->refresh();

            if ($checkout->status !== RenewalCheckoutStatus::PendingPayment) {
                throw new \RuntimeException('Bukti pembayaran hanya dapat diunggah saat status checkout masih pending_payment.');
            }

            $disk = config('renewals.proof.storage_disk', 'local');
            $base = config('renewals.proof.storage_path', 'renewal-payment-proofs');
            $dir = "{$base}/{$checkout->user_id}";
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $name = Str::uuid()->toString().'.'.$ext;
            $path = $file->storeAs($dir, $name, $disk);

            if ($checkout->proof_path !== null && $checkout->proof_disk !== null) {
                Storage::disk($checkout->proof_disk)->delete($checkout->proof_path);
            }

            $checkout->forceFill([
                'proof_disk' => $disk,
                'proof_path' => $path,
                'status' => RenewalCheckoutStatus::AwaitingReview,
                'payment_proof_submitted_at' => now(),
            ])->save();

            return $checkout->fresh();
        });
    }

    /**
     * User cancels checkout only while waiting for payment (before proof upload).
     * Removes stored proof file if present (edge case).
     *
     * @throws \RuntimeException When status does not allow cancellation.
     */
    public function cancelCheckout(RenewalCheckout $checkout, User $user): RenewalCheckout
    {
        return DB::transaction(function () use ($checkout, $user) {
            $checkout->refresh();

            if ((int) $checkout->user_id !== (int) $user->id) {
                throw new \RuntimeException('Checkout tidak ditemukan untuk user ini.');
            }

            if ($checkout->status !== RenewalCheckoutStatus::PendingPayment) {
                throw new \RuntimeException('Checkout ini tidak dapat dibatalkan.');
            }

            if ($checkout->proof_path !== null && $checkout->proof_disk !== null) {
                Storage::disk($checkout->proof_disk)->delete($checkout->proof_path);
            }

            $checkout->forceFill([
                'status' => RenewalCheckoutStatus::Cancelled,
                'proof_disk' => null,
                'proof_path' => null,
                'payment_proof_submitted_at' => null,
            ])->save();

            return $checkout->fresh();
        });
    }

    /**
     * Admin approves or rejects a checkout after proof was submitted.
     *
     * @throws \RuntimeException When checkout is not awaiting_review or already final.
     */
    public function adminReview(RenewalCheckout $checkout, User $admin, bool $approve, ?string $notes = null): RenewalCheckout
    {
        return DB::transaction(function () use ($checkout, $admin, $approve, $notes) {
            $checkout->refresh();

            if ($checkout->status !== RenewalCheckoutStatus::AwaitingReview) {
                throw new \RuntimeException('Checkout tidak dalam status menunggu review admin.');
            }

            if ($approve) {
                $checkout->loadMissing(['user', 'plan']);
                if ($checkout->plan === null) {
                    throw new \RuntimeException('Plan checkout tidak ditemukan.');
                }
                $this->subscriptionService->grantRenewalPeriod($checkout->user, $checkout->plan);
                $checkout->forceFill([
                    'status' => RenewalCheckoutStatus::Verified,
                    'reviewed_at' => now(),
                    'reviewed_by' => $admin->id,
                    'admin_notes' => null,
                ])->save();
            } else {
                $checkout->forceFill([
                    'status' => RenewalCheckoutStatus::Rejected,
                    'reviewed_at' => now(),
                    'reviewed_by' => $admin->id,
                    'admin_notes' => $notes,
                ])->save();
            }

            return $checkout->fresh();
        });
    }
}
