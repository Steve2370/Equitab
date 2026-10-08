<?php

namespace App\Mail;

use App\Models\GroupMember;
use App\Models\Payment;
use App\Support\MoneyFormatter;
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
            subject: 'Votre paiement a été confirmé '.$this->payment->group->name,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $amount = MoneyFormatter::format($this->payment->amount, $this->payment->currency);
        // Preserve the existing smaller currency label in the amount heading.
        [$amountValue, $amountCurrencyLabel] = explode("\u{00A0}", $amount, 2);

        return new Content(
            view: 'emails.payment.confirmed',
            with: [
                'memberName' => $this->member->user->name,
                'groupName' => $this->payment->group->name,
                'subscriptionName' => $this->payment->group->subscription->name,
                'amount' => $amount,
                'amountValue' => $amountValue,
                'amountCurrencyLabel' => $amountCurrencyLabel,
                'nextBillingDate' => $this->member->next_payment_at?->format('d M Y'),
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
