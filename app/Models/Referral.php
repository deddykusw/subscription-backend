<?php

namespace App\Models;

use App\Enums\ReferralStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Represents a single referral relationship between two users.
 *
 * @property int    $id
 * @property int    $referrer_user_id
 * @property int    $referred_user_id
 * @property string $referral_code
 * @property ReferralStatus $status
 * @property Carbon $referred_at
 * @property Carbon|null $completed_at
 *
 * @method static Builder|static pending()
 * @method static Builder|static completed()
 */
class Referral extends Model
{
    protected $fillable = [
        'referrer_user_id',
        'referred_user_id',
        'referral_code',
        'status',
        'referred_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'       => ReferralStatus::class,
            'referred_at'  => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** The user who made the referral (the referrer). */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    /** The user who was referred (the new user). */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    /** The commission record generated when this referral is completed. */
    public function commission(): HasOne
    {
        return $this->hasOne(Commission::class);
    }

    // -------------------------------------------------------------------------
    // Business methods
    // -------------------------------------------------------------------------

    /**
     * Marks the referral as completed and records the timestamp.
     * No-op if the referral is already in a final state.
     */
    public function markCompleted(): void
    {
        if ($this->status->isFinal()) {
            return;
        }

        $this->update([
            'status'       => ReferralStatus::Completed,
            'completed_at' => Carbon::now(),
        ]);
    }

    /**
     * Cancels the referral.
     * No-op if the referral is already in a final state.
     */
    public function cancel(): void
    {
        if ($this->status->isFinal()) {
            return;
        }

        $this->update(['status' => ReferralStatus::Cancelled]);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /** Filter to referrals awaiting first subscription payment. */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ReferralStatus::Pending);
    }

    /** Filter to referrals where the referred user has paid. */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', ReferralStatus::Completed);
    }
}
