<?php

namespace App\Features\Group\Services;

use App\Models\Subscription;
use Illuminate\Validation\ValidationException;

final class ServiceAccessPublication
{
    public function validate(Subscription $subscription, array $credentials): void
    {
        if ($subscription->access_mode !== 'invitation') {
            return;
        }
        foreach (['credential_email', 'credential_password', 'credential_notes'] as $key) {
            if (filled($credentials[$key] ?? null)) {
                throw ValidationException::withMessages([$key => 'Ce service utilise des invitations individuelles. Ne saisissez aucun mot de passe ou coffre personnel.']);
            }
        }
    }
}
