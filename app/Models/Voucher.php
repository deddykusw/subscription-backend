<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

#[Fillable([
    'code',
    'duration_days',
    'plan_id',
    'max_uses',
    'used_count',
    'is_active',
    'valid_from',
    'valid_until',
    'notes',
    'created_by',
])]
class Voucher extends Model
{
    // ── Relationships ─────────────────────────────────────────────────────────

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<VoucherRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(VoucherRedemption::class);
    }

    // ── Business methods ──────────────────────────────────────────────────────

    /** Returns true when this voucher can still be redeemed right now. */
    public function isUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = Carbon::now();

        if ($this->valid_from && $this->valid_from->gt($now)) {
            return false;
        }

        if ($this->valid_until && $this->valid_until->lt($now)) {
            return false;
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    /** Human-readable reason why the voucher cannot be used (or null when usable). */
    public function unusableReason(): ?string
    {
        if (! $this->is_active) {
            return 'Voucher tidak aktif.';
        }

        $now = Carbon::now();

        if ($this->valid_from && $this->valid_from->gt($now)) {
            return 'Voucher belum berlaku. Berlaku mulai ' . $this->valid_from->translatedFormat('d M Y') . '.';
        }

        if ($this->valid_until && $this->valid_until->lt($now)) {
            return 'Voucher sudah kadaluarsa.';
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return 'Kuota voucher sudah habis.';
        }

        return null;
    }

    // ── Static helpers ────────────────────────────────────────────────────────

    /** Generates a unique, readable voucher code in the format XXXX-XXXX-XXXX. */
    public static function generateCode(): string
    {
        do {
            $code = strtoupper(
                Str::random(4) . '-' . Str::random(4) . '-' . Str::random(4)
            );
        } while (static::where('code', $code)->exists());

        return $code;
    }

    // ── Casts ─────────────────────────────────────────────────────────────────

    protected function casts(): array
    {
        return [
            'is_active'    => 'boolean',
            'duration_days'=> 'integer',
            'max_uses'     => 'integer',
            'used_count'   => 'integer',
            'valid_from'   => 'datetime',
            'valid_until'  => 'datetime',
        ];
    }
}
