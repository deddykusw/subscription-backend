<?php

namespace App\Enums;

enum RenewalCheckoutStatus: string
{
    case PendingPayment = 'pending_payment';
    case AwaitingReview = 'awaiting_review';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function isFinal(): bool
    {
        return $this === self::Verified || $this === self::Rejected;
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Menunggu pembayaran',
            self::AwaitingReview => 'Menunggu review',
            self::Verified => 'Disetujui',
            self::Rejected => 'Ditolak',
        };
    }
}
