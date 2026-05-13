<?php

namespace App\Enums;

enum RenewalCheckoutStatus: string
{
    case PendingPayment = 'pending_payment';
    case AwaitingReview = 'awaiting_review';
}
