<?php

namespace App\Mail;

use App\Models\Payment;
use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AutoRefundProcessed extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public readonly Payment $payment,
        public readonly User $user,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Remboursement effectué: Equitab',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.payment.refund',
            with: [
                'userName' => $this->user->name,
                'groupName' => $this->payment->group->name,
                'subscriptionName' => $this->payment->group->subscription->name,
                'amount' => MoneyFormatter::format($this->payment->amount, $this->payment->currency),
                'dashboardUrl' => config('app.url').'/dashboard/payments',
                'supportUrl' => 'mailto:support@equitab.ca',
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
