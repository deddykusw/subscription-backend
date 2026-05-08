<?php

namespace App\Enums;

enum ReferralStatus: string
{
    case Pending   = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Pending   => 'Pending',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isFinal(): bool
    {
        return match($this) {
            self::Completed, self::Cancelled => true,
            default                          => false,
        };
    }
}
