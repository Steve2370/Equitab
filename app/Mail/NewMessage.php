<?php

namespace App\Mail;

use App\Models\Message;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $recipient,
        public readonly User $sender,
        public readonly string $groupName,
        public readonly string $messagePreview,
        public readonly int $groupId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nouveau message de ' . $this->sender->name . ' - Equitab',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.chat.new-message',
            with: [
                'recipientName'  => $this->recipient->name,
                'senderName' => $this->sender->name,
                'groupName' => $this->groupName,
                'messagePreview' => $this->messagePreview,
                'chatUrl' => config('app.url') . '/dashboard/chat',
            ],
        );
    }
}
