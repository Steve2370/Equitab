<?php

namespace App\Features\Group\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class OwnerPublicationEligibility
{
    public function assertCanPrepare(User $user): void
    {
        if ($user->isSuspended() || $user->status === 'banned' || ! $user->hasVerifiedEmail()) {
            throw new AuthorizationException('Votre compte doit être autorisé et votre courriel confirmé.');
        }
    }

    /** @return array{identityVerified: bool, connectActive: bool, ready: bool, identityStatus: string, connectStatus: string} */
    public function state(User $user): array
    {
        $identityVerified = $user->identity_status === 'verified';
        $connectActive = $user->stripe_connect_status === 'active';

        return [
            'identityVerified' => $identityVerified,
            'connectActive' => $connectActive,
            'ready' => $identityVerified && $connectActive && ! $user->isSuspended() && $user->status !== 'banned' && $user->hasVerifiedEmail(),
            'identityStatus' => $user->identity_status ?? 'unverified',
            'connectStatus' => $user->stripe_connect_status ?? 'not_started',
        ];
    }

    public function assertCanPublish(User $user): void
    {
        $this->assertCanPrepare($user);

        if (! $this->state($user)['ready']) {
            throw ValidationException::withMessages([
                'activation' => 'Vérifiez votre identité et activez les versements avant de publier. Votre brouillon reste privé.',
            ]);
        }
    }
}
