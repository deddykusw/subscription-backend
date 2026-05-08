<?php

namespace App\Enums;

enum PaymentOrderStatus: string
{
    case Pending   = 'pending';
    case Verified  = 'verified';
    case Rejected  = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Pending   => 'Pending',
            self::Verified  => 'Verified',
            self::Rejected  => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isFinal(): bool
    {
        return match($this) {
            self::Verified, self::Rejected, self::Cancelled => true,
            default                                          => false,
        };
    }
}
