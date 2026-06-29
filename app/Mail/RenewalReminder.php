<?php

namespace App\Mail;

use App\Models\GroupMember;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RenewalReminder extends Mailable
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
            subject: 'Rappel de Votre abonnement ' . $this->member->group->subscription->name . ' se renouvelle dans 3 jours',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription.renewal-reminder',
            with: [
                'memberName' => $this->member->user->name,
                'subscriptionName' => $this->member->group->subscription->name,
                'groupName' => $this->member->group->name,
                'amount' => number_format($this->member->share_amount / 100, 2),
                'renewalDate' => $this->member->next_payment_at?->format('d M Y'),
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
