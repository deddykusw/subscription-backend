<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\AttendanceProfile;
use App\Models\Message;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'external_user_id', 'is_admin', 'referred_by', 'referral_code_used', 'username', 'attendance_server_id', 'last_token_validation_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return HasMany<PaymentOrder, $this> */
    public function paymentOrders(): HasMany
    {
        return $this->hasMany(PaymentOrder::class);
    }

    // ── Referral relationships ──────────────────────────────────────────────

    /**
     * The user who referred this user (nullable — null if not referred).
     *
     * @return BelongsTo<User, $this>
     */
    public function referredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    /**
     * Users that this user has directly referred.
     *
     * @return HasMany<User, $this>
     */
    public function referredUsers(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    /**
     * This user's referral profile (code, earnings, stats).
     *
     * @return HasOne<UserReferral, $this>
     */
    public function userReferral(): HasOne
    {
        return $this->hasOne(UserReferral::class);
    }

    /**
     * Commissions earned by this user as a referrer.
     *
     * @return HasMany<Commission, $this>
     */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'referrer_user_id');
    }

    /**
     * Commission payout requests made by this user.
     *
     * @return HasMany<CommissionPayout, $this>
     */
    public function commissionPayouts(): HasMany
    {
        return $this->hasMany(CommissionPayout::class);
    }

    /**
     * Attendance server profile — token, is_active, jabatan, etc.
     * Populated by ExternalAuthService when the user exchanges their
     * attendance token via POST /api/v1/auth/exchange-token.
     *
     * @return HasOne<AttendanceProfile, $this>
     */
    public function attendanceProfile(): HasOne
    {
        return $this->hasOne(AttendanceProfile::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'user_id');
    }

    // -------------------------------------------------------------------------
    // Business methods
    // -------------------------------------------------------------------------

    /**
     * Returns the most relevant current subscription (active takes priority over trial).
     * Returns null when the user has no valid subscription.
     */
    public function getCurrentSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trial->value])
            ->where('end_date', '>=', Carbon::today())
            ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [SubscriptionStatus::Active->value])
            ->orderByDesc('end_date')
            ->first();
    }

    /** Returns true when the user has a non-expired active subscription. */
    public function hasActiveSubscription(): bool
    {
        return $this->subscriptions()
            ->where('status', SubscriptionStatus::Active)
            ->where('end_date', '>=', Carbon::today())
            ->exists();
    }

    /** Returns true when the user's current subscription is in the trial period. */
    public function isOnTrial(): bool
    {
        return $this->subscriptions()
            ->where('status', SubscriptionStatus::Trial)
            ->where('end_date', '>=', Carbon::today())
            ->exists();
    }

    /**
     * Returns the number of days remaining in the trial period.
     * Returns 0 when the user is not on trial or the trial has expired.
     */
    public function getRemainingTrialDays(): int
    {
        $subscription = $this->subscriptions()
            ->where('status', SubscriptionStatus::Trial)
            ->where('end_date', '>=', Carbon::today())
            ->orderByDesc('end_date')
            ->first();

        if ($subscription === null) {
            return 0;
        }

        // trial_end_date is the canonical field; fall back to end_date when absent
        $trialEnd = $subscription->trial_end_date ?? $subscription->end_date;

        return max(0, (int) Carbon::today()->diffInDays($trialEnd, false));
    }

    // -------------------------------------------------------------------------
    // Casts
    // -------------------------------------------------------------------------

    protected function casts(): array
    {
        return [
            'email_verified_at'          => 'datetime',
            'password'                   => 'hashed',
            'is_admin'                   => 'boolean',
            'attendance_server_id'       => 'integer',
            'last_token_validation_at'   => 'datetime',
        ];
    }
}
