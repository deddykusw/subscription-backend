<?php

namespace App\Models;

use App\Enums\RenewalCheckoutStatus;
use App\Enums\RenewalPeriod;
use Database\Factories\RenewalCheckoutFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RenewalCheckout extends Model
{
    /** @use HasFactory<RenewalCheckoutFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'subscription_plan_id',
        'period',
        'status',
        'amount',
        'currency',
        'proof_disk',
        'proof_path',
        'payment_proof_submitted_at',
        'upload_deadline_at',
        'reviewed_at',
        'reviewed_by',
        'admin_notes',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    protected function casts(): array
    {
        return [
            'period' => RenewalPeriod::class,
            'status' => RenewalCheckoutStatus::class,
            'amount' => 'decimal:2',
            'payment_proof_submitted_at' => 'datetime',
            'upload_deadline_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
