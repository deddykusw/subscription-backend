<?php

namespace App\Mail;

use App\Models\Commission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CommissionEarnedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Commission $commission) {}

    public function envelope(): Envelope
    {
        $statusLabel = $this->commission->status->label();

        return new Envelope(
            subject: "Commission {$statusLabel} — You Earned IDR " .
                     number_format((float) $this->commission->amount, 0, '.', ','),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.referral.commission_earned',
        );
    }
}
