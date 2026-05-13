<?php

namespace App\Exceptions;

use App\Enums\RenewalCheckoutStatus;

/**
 * Thrown when a user tries to open a new renewal checkout while another is still
 * {@see RenewalCheckoutStatus::PendingPayment} or {@see RenewalCheckoutStatus::AwaitingReview}.
 */
class RenewalInProgressException extends \Exception
{
    public function __construct(
        string $message = 'Masih ada proses perpanjangan yang berlangsung. Selesaikan pembayaran, unggah bukti, atau tunggu verifikasi admin terlebih dahulu.',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
