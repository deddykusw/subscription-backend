<?php

namespace App\Models;

use App\Enums\CommissionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Represents a commission earned by a referrer when their referred user
 * completes a subscription payment.
 *
 * @property int    $id
 * @property int    $referrer_user_id
 * @property int    $referred_user_id
 * @property int    $referral_id
 * @property int    $subscription_id
 * @property int    $payment_order_id
 * @property float  $amount
 * @property float  $commission_percentage
 * @property CommissionStatus $status
 * @property Carbon|null $credited_at
 * @property Carbon|null $paid_out_at
 * @property string|null $notes
 *
 * @method static Builder|static pending()
 * @method static Builder|static credited()
 */
class Commission extends Model
{
    protected $fillable = [
        'referrer_user_id',
        'referred_user_id',
        'referral_id',
        'subscription_id',
        'payment_order_id',
        'amount',
        'commission_percentage',
        'status',
        'credited_at',
        'paid_out_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount'                => 'float',
            'commission_percentage' => 'float',
            'status'                => CommissionStatus::class,
            'credited_at'           => 'datetime',
            'paid_out_at'           => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** The user receiving the commission. */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    /** The user whose payment triggered this commission. */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    /** The originating referral record. */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    /** The subscription that was activated by the payment. */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** The verified payment order that triggered this commission. */
    public function paymentOrder(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class);
    }

    // -------------------------------------------------------------------------
    // Business methods
    // -------------------------------------------------------------------------

    /**
     * Marks the commission as credited to the referrer's balance.
     * Also updates the referrer's UserReferral earnings.
     * No-op if already credited or cancelled.
     */
    public function credit(): void
    {
        if ($this->status !== CommissionStatus::Pending) {
            return;
        }

        $this->update([
            'status'      => CommissionStatus::Credited,
            'credited_at' => Carbon::now(),
        ]);

        // Update the referrer's running totals.
        $userReferral = UserReferral::where('user_id', $this->referrer_user_id)->first();
        $userReferral?->addEarnings($this->amount);
    }

    /**
     * Cancels a pending or credited commission.
     *
     * @param string|null $reason  Optional reason stored in the notes field.
     */
    public function cancel(?string $reason = null): void
    {
        if ($this->status->isFinal()) {
            return;
        }

        $this->update([
            'status' => CommissionStatus::Cancelled,
            'notes'  => $reason ?? $this->notes,
        ]);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /** Filter to commissions not yet added to the referrer's balance. */
    public function scopePending(Builder $query): void
    {
        $query->where('status', CommissionStatus::Pending);
    }

    /** Filter to commissions already added to the referrer's balance. */
    public function scopeCredited(Builder $query): void
    {
        $query->where('status', CommissionStatus::Credited);
    }
}
