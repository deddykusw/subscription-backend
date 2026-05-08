<?php

namespace App\Enums;

enum CommissionStatus: string
{
    case Pending   = 'pending';
    case Credited  = 'credited';
    case PaidOut   = 'paid_out';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Pending   => 'Pending',
            self::Credited  => 'Credited',
            self::PaidOut   => 'Paid Out',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isFinal(): bool
    {
        return match($this) {
            self::PaidOut, self::Cancelled => true,
            default                        => false,
        };
    }
}
