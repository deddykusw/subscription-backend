<?php

namespace App\Mail;

use App\Models\PaymentOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentVerifiedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly PaymentOrder $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment Verified — Your Subscription is Now Active',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.payment.verified',
        );
    }
}
