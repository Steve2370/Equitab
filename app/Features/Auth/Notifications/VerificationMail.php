<?php

namespace App\Features\Auth\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/** Presentation only: Laravel still owns the signed URL and its validation. */
final class VerificationMail
{
    public function __invoke(User $user, string $url): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirmez votre courriel pour commencer sur EquitAb')
            ->action('Confirmer mon courriel', $url)
            ->markdown('emails.auth.verify-email', [
                'userName' => $user->name,
                'expiresInMinutes' => (int) config('auth.verification.expire', 60),
            ]);
    }
}
