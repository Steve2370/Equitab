<?php

namespace App\Features\Payment\Services;

use App\Features\Payment\Contracts\OwnerStripeGatewayInterface;
use App\Mail\ConnectAccountActivated;
use App\Models\StripeEvent;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class OwnerConnectWebhookService
{
    public function __construct(
        private readonly OwnerStripeGatewayInterface $gateway,
        private readonly OwnerOnboardingLock $lock,
    ) {}

    public function synchronize(string $eventId, string $accountId): void
    {
        $owner = User::query()->where('stripe_connect_account_id', $accountId)->first();
        if (! $owner) {
            return;
        }
        $recipient = $this->lock->run($owner->id, function (Closure $assertOwned) use ($owner, $eventId, $accountId): ?User {
            if (StripeEvent::where('stripe_event_id', $eventId)->exists()) {
                return null;
            }

            // Event payloads can arrive in any order: only Stripe's current
            // state is projected, under the same lock used by the return path.
            $status = $this->gateway->retrieveAccount($accountId)->status();

            return DB::transaction(function () use ($owner, $eventId, $accountId, $status, $assertOwned): ?User {
                $user = User::query()->lockForUpdate()->findOrFail($owner->id);
                $assertOwned();
                if ($user->stripe_connect_account_id !== $accountId) {
                    throw new OwnerOnboardingException;
                }
                $wasActive = $user->stripe_connect_status === 'active';
                $user->update(['stripe_connect_status' => $status]);

                StripeEvent::create([
                    'stripe_event_id' => $eventId,
                    'type' => 'account.updated',
                    'payload' => ['account_id' => $accountId],
                    'processed_at' => now(),
                ]);

                return ! $wasActive && $status === 'active' ? $user : null;
            });
        });

        if ($recipient === null) {
            return;
        }

        // The state/event are committed and both locks released before mail I/O.
        // Delivery is best-effort: an email failure must not retry Stripe state.
        try {
            Mail::to($recipient->email)->send(new ConnectAccountActivated($recipient));
        } catch (Throwable) {
            Log::warning('Courriel d’activation Stripe Connect non envoyé.', [
                'user_id' => $recipient->id,
                'stripe_event_id' => $eventId,
            ]);
        }
    }
}
