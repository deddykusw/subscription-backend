<?php

namespace App\Enums;

enum CommissionPayoutStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Completed  = 'completed';
    case Failed     = 'failed';

    public function label(): string
    {
        return match($this) {
            self::Pending    => 'Pending',
            self::Processing => 'Processing',
            self::Completed  => 'Completed',
            self::Failed     => 'Failed',
        };
    }

    public function isFinal(): bool
    {
        return match($this) {
            self::Completed, self::Failed => true,
            default                       => false,
        };
    }
}
