<?php

namespace App\Mail;

use App\Models\GroupMember;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PriceChanged extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public readonly GroupMember $member,
        public readonly int $oldPrice,
        public readonly int $newPrice,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $direction = $this->newPrice < $this->oldPrice ? 'baissé' : 'augmenté';

        return new Envelope(
            subject: "Votre prix a {$direction} — ".$this->member->group->subscription->name,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription.price-changed',
            with: [
                'memberName' => $this->member->user->name,
                'groupName' => $this->member->group->name,
                'subscriptionName' => $this->member->group->subscription->name,
                // Public mailable properties are injected after view data.
                // Keep display values distinct from the integer cent amounts.
                'displayOldPrice' => number_format($this->oldPrice / 100, 2),
                'displayNewPrice' => number_format($this->newPrice / 100, 2),
                'isDecrease' => $this->newPrice < $this->oldPrice,
                'dashboardUrl' => config('app.url').'/dashboard/subscriptions',
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
