<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Subscription extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'plan_id',
        'status',
        'trial_start_date',
        'trial_end_date',
        'start_date',
        'end_date',
        'auto_renew',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /** Subscriptions with status=active and a future end date. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Active)
                     ->where('end_date', '>=', Carbon::today());
    }

    /** Subscriptions currently in the trial period. */
    public function scopeTrial(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Trial)
                     ->where('end_date', '>=', Carbon::today());
    }

    /**
     * Subscriptions that are logically expired: either status=expired
     * or end_date has passed regardless of the stored status flag.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('status', SubscriptionStatus::Expired)
              ->orWhere('end_date', '<', Carbon::today());
        });
    }

    // -------------------------------------------------------------------------
    // Business methods
    // -------------------------------------------------------------------------

    /** Returns true when status is active AND the end date has not passed. */
    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active
            && $this->end_date->greaterThanOrEqualTo(Carbon::today());
    }

    /** Returns true when the end date has passed OR status is marked expired. */
    public function isExpired(): bool
    {
        return $this->status === SubscriptionStatus::Expired
            || $this->end_date->lessThan(Carbon::today());
    }

    /**
     * Returns the number of days between today and end_date.
     * Returns 0 when the subscription has already expired.
     */
    public function getRemainingDays(): int
    {
        if ($this->isExpired()) {
            return 0;
        }

        return (int) Carbon::today()->diffInDays($this->end_date);
    }

    /**
     * Marks the subscription as active.
     * Business validation (e.g. checking payment) belongs in the service layer.
     */
    public function activate(): bool
    {
        $this->status = SubscriptionStatus::Active;

        return $this->save();
    }

    // -------------------------------------------------------------------------
    // Casts
    // -------------------------------------------------------------------------

    protected function casts(): array
    {
        return [
            'status'           => SubscriptionStatus::class,
            'trial_start_date' => 'date',
            'trial_end_date'   => 'date',
            'start_date'       => 'date',
            'end_date'         => 'date',
            'auto_renew'       => 'boolean',
        ];
    }
}
