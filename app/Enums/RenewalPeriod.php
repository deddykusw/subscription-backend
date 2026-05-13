<?php

namespace App\Enums;

enum RenewalPeriod: string
{
    case Month = 'month';
    case Year = 'year';

    public function planSlug(): string
    {
        return match ($this) {
            self::Month => 'monthly',
            self::Year => 'yearly',
        };
    }
}
