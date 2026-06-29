<?php

namespace App\Mail;

use App\Models\Payment;
use App\Models\GroupMember;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentConfirmed extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public readonly Payment $payment,
        public readonly GroupMember $member,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Votre paiement a été confirmé ' . $this->payment->group->name,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.payment.confirmed',
            with: [
                'memberName' => $this->member->user->name,
                'groupName' => $this->payment->group->name,
                'subscriptionName' => $this->payment->group->subscription->name,
                'amount' => number_format($this->payment->amount / 100, 2),
                'nextBillingDate' => $this->member->next_payment_at?->format('d M Y'),
                'dashboardUrl' => config('app.url') . '/dashboard/subscriptions',
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
