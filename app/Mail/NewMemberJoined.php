<?php

namespace App\Mail;

use App\Models\Group;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewMemberJoined extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public readonly Group $group,
        public readonly User $newMember,
        public readonly ?int $amountInCents = null,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nouveau membre dans votre groupe ' . $this->group->name,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.group.new-member',
            with: [
                'ownerName' => $this->group->owner->name,
                'memberName' => $this->newMember->name,
                'groupName' => $this->group->name,
                'subscriptionName' => $this->group->subscription->name,
                'pricePerMember' => number_format(($this->amountInCents ?? $this->group->calculateCurrentPricePerMember()) / 100, 2),
                'spotsLeft' => $this->group->max_members - $this->group->current_members,
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
