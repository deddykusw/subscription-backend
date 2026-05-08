<?php

namespace App\Models;

use App\Enums\CommissionPayoutStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Represents an admin-processed payout of accumulated commissions to a user.
 *
 * @property int    $id
 * @property int    $user_id
 * @property float  $amount
 * @property string $payout_method
 * @property array  $payout_details
 * @property CommissionPayoutStatus $status
 * @property int|null    $processed_by
 * @property Carbon|null $processed_at
 * @property string|null $notes
 *
 * @method static Builder|static pending()
 */
class CommissionPayout extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        'payout_method',
        'payout_details',
        'status',
        'processed_by',
        'processed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'float',
            'payout_details' => 'array',
            'status'         => CommissionPayoutStatus::class,
            'processed_at'   => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** The user receiving the payout. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The admin who processed this payout. */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    // -------------------------------------------------------------------------
    // Business methods
    // -------------------------------------------------------------------------

    /**
     * Marks the payout as successfully completed.
     * Deducts the amount from the user's pending earnings.
     *
     * @param User $admin  The admin confirming the payout.
     */
    public function markCompleted(User $admin): void
    {
        if ($this->status->isFinal()) {
            return;
        }

        $this->update([
            'status'       => CommissionPayoutStatus::Completed,
            'processed_by' => $admin->id,
            'processed_at' => Carbon::now(),
        ]);

        // Deduct from the user's referral balance.
        $userReferral = UserReferral::where('user_id', $this->user_id)->first();
        $userReferral?->deductPaidOut($this->amount);

        // Mark associated credited commissions as paid_out.
        Commission::where('referrer_user_id', $this->user_id)
            ->where('status', \App\Enums\CommissionStatus::Credited)
            ->update([
                'status'      => \App\Enums\CommissionStatus::PaidOut,
                'paid_out_at' => Carbon::now(),
            ]);
    }

    /**
     * Marks the payout as failed and records the reason.
     *
     * @param string $reason  Human-readable failure reason stored in notes.
     * @param User   $admin   The admin recording the failure.
     */
    public function markFailed(string $reason, User $admin): void
    {
        if ($this->status->isFinal()) {
            return;
        }

        $this->update([
            'status'       => CommissionPayoutStatus::Failed,
            'processed_by' => $admin->id,
            'processed_at' => Carbon::now(),
            'notes'        => $reason,
        ]);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /** Filter to payouts awaiting admin action. */
    public function scopePending(Builder $query): void
    {
        $query->whereIn('status', [
            CommissionPayoutStatus::Pending,
            CommissionPayoutStatus::Processing,
        ]);
    }
}
