<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Tracks a user's referral profile: their unique code, referral count,
 * and cumulative earnings.
 *
 * @property int    $id
 * @property int    $user_id
 * @property string $referral_code
 * @property int    $total_referrals
 * @property float  $total_earnings
 * @property float  $total_paid_out
 * @property float  $pending_earnings
 * @property bool   $is_active
 */
class UserReferral extends Model
{
    protected $fillable = [
        'user_id',
        'referral_code',
        'total_referrals',
        'total_earnings',
        'total_paid_out',
        'pending_earnings',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'total_earnings'   => 'float',
            'total_paid_out'   => 'float',
            'pending_earnings' => 'float',
            'is_active'        => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** The user who owns this referral profile. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * All referral records where this user is the referrer.
     *
     * @return HasMany<Referral, $this>
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_user_id', 'user_id');
    }

    // -------------------------------------------------------------------------
    // Business methods
    // -------------------------------------------------------------------------

    /**
     * Generates a unique 8-character uppercase referral code.
     * Retries until uniqueness is guaranteed.
     */
    public function generateReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (static::where('referral_code', $code)->exists());

        $this->referral_code = $code;

        return $code;
    }

    /**
     * Atomically increments the total referral count.
     */
    public function incrementReferrals(): void
    {
        $this->increment('total_referrals');
    }

    /**
     * Adds earned commission to both total_earnings and pending_earnings.
     *
     * @param float $amount  Amount in IDR (or configured currency).
     */
    public function addEarnings(float $amount): void
    {
        $this->increment('total_earnings', $amount);
        $this->increment('pending_earnings', $amount);
    }

    /**
     * Records a payout by adding to total_paid_out and deducting from pending_earnings.
     *
     * @param float $amount  Amount being paid out.
     *
     * @throws \UnderflowException  When payout exceeds available pending earnings.
     */
    public function deductPaidOut(float $amount): void
    {
        if ($amount > $this->pending_earnings) {
            throw new \UnderflowException(
                "Payout amount ({$amount}) exceeds pending earnings ({$this->pending_earnings})."
            );
        }

        $this->increment('total_paid_out', $amount);
        $this->decrement('pending_earnings', $amount);
    }
}
