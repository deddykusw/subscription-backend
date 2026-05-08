<?php

namespace App\Models;

use App\Enums\PaymentOrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subscription_id',
        'plan_id',
        'amount',
        'currency',
        'payment_method',
        'status',
        'paypal_email',
        'transaction_id',
        'proof_screenshot_url',
        'verified_by',
        'verified_at',
        'notes',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    /**
     * The admin who reviewed this order.
     *
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /** Orders awaiting admin review. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PaymentOrderStatus::Pending);
    }

    /** Orders that have been approved by an admin. */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', PaymentOrderStatus::Verified);
    }

    // -------------------------------------------------------------------------
    // Business methods
    // -------------------------------------------------------------------------

    /**
     * Marks the order as verified by the given admin user.
     * Does NOT automatically activate the linked subscription —
     * that responsibility belongs to the service layer.
     */
    public function verify(User $verifier): bool
    {
        $this->status      = PaymentOrderStatus::Verified;
        $this->verified_by = $verifier->id;
        $this->verified_at = now();

        return $this->save();
    }

    /**
     * Marks the order as rejected by the given admin user.
     *
     * @param string|null $notes Optional reason visible to the customer.
     */
    public function reject(User $verifier, ?string $notes = null): bool
    {
        $this->status      = PaymentOrderStatus::Rejected;
        $this->verified_by = $verifier->id;
        $this->verified_at = now();

        if ($notes !== null) {
            $this->notes = $notes;
        }

        return $this->save();
    }

    // -------------------------------------------------------------------------
    // Casts
    // -------------------------------------------------------------------------

    protected function casts(): array
    {
        return [
            'status'      => PaymentOrderStatus::class,
            'amount'      => 'decimal:2',
            'verified_at' => 'datetime',
        ];
    }
}
