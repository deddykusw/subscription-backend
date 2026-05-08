<?php

namespace App\Enums;

enum ReferralSettingType: string
{
    case Percentage = 'percentage';
    case Amount     = 'amount';
    case Boolean    = 'boolean';
    case StringType = 'string';

    public function label(): string
    {
        return match($this) {
            self::Percentage => 'Percentage (%)',
            self::Amount     => 'Amount (IDR)',
            self::Boolean    => 'Boolean (true/false)',
            self::StringType => 'String',
        };
    }
}
