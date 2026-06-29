<?php

namespace App\Mail;

use App\Models\GroupMember;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;


class PaymentFailed extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public readonly GroupMember $member,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Échec de paiement ' . $this->member->group->subscription->name,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.payment.failed',
            with: [
                'memberName' => $this->member->user->name,
                'subscriptionName' => $this->member->group->subscription->name,
                'groupName' => $this->member->group->name,
                'amount' => number_format($this->member->share_amount / 100, 2),
                'dashboardUrl' => config('app.url') . '/dashboard/subscriptions',
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
