<?php

namespace App\Features\Payment\Services;

use App\Features\Payment\Contracts\OwnerStripeGatewayInterface;
use App\Mail\IdentityVerified;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class OwnerIdentityWebhookService
{
    public function __construct(
        private readonly OwnerStripeGatewayInterface $gateway,
        private readonly OwnerOnboardingLock $lock,
    ) {}

    /** Return a notification recipient only on an actual verified transition. */
    public function synchronize(string $sessionId): ?User
    {
        // Metadata is not an ownership proof. An old session must never
        // overwrite the state of the owner's current verification session.
        $owner = User::query()->where('stripe_identity_session_id', $sessionId)->first();
        if ($owner === null) {
            return null;
        }

        return $this->lock->run($owner->id, function (Closure $assertOwned) use ($owner, $sessionId): ?User {
            $current = $owner->fresh();
            if ($current === null || $current->stripe_identity_session_id !== $sessionId) {
                return null;
            }

            $session = $this->gateway->retrieveIdentity($sessionId);
            if ($session->id !== $sessionId) {
                throw new OwnerOnboardingException;
            }
            $status = $session->status();

            // Remote I/O precedes this short transaction. All competing
            // onboarding writers use this same owner lease and row lock.
            return DB::transaction(function () use ($owner, $sessionId, $status, $assertOwned): ?User {
                $user = User::query()->lockForUpdate()->findOrFail($owner->id);
                $assertOwned();
                if ($user->stripe_identity_session_id !== $sessionId) {
                    throw new OwnerOnboardingException;
                }

                $wasVerified = $user->identity_status === 'verified';
                $user->update([
                    'identity_status' => $status,
                    'identity_verified_at' => $status === 'verified' ? ($user->identity_verified_at ?? now()) : null,
                ]);

                return ! $wasVerified && $status === 'verified' ? $user : null;
            });
        });
    }

    /** Called only after the state, receipt and all coordination locks finish. */
    public function notifyVerified(User $recipient, string $eventId): void
    {
        try {
            Mail::to($recipient->email)->send(new IdentityVerified($recipient));
        } catch (Throwable) {
            Log::warning('Courriel de vérification Identity non envoyé.', [
                'user_id' => $recipient->id,
                'stripe_event_id' => $eventId,
            ]);
        }
    }
}
