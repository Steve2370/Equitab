<?php

namespace App\Features\Payment\Services;

use App\Features\Payment\Contracts\OwnerStripeGatewayInterface;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class OwnerOnboardingService
{
    public function __construct(
        private readonly OwnerStripeGatewayInterface $gateway,
        private readonly OwnerConnectAccountService $accounts,
        private readonly OwnerOnboardingAccess $access,
        private readonly OwnerOnboardingLock $lock,
    ) {}

    public function start(User $user, ?string $draftId = null): string
    {
        try {
            $accountId = $this->accounts->accountId($user, $draftId);
            $query = $draftId === null ? [] : ['draft_id' => $draftId];

            return $this->gateway->createAccountLink(
                $accountId,
                route('stripe.onboarding.return', $query),
                route('stripe.onboarding.refresh', $query),
            );
        } catch (AuthorizationException|ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            throw new OwnerOnboardingException;
        }
    }

    public function refresh(User $user): void
    {
        try {
            $this->lock->run($user->id, function (Closure $assertOwned) use ($user): void {
                $current = $user->fresh();
                $this->access->authorize($current);
                $attributes = ['stripe_connect_status' => 'not_started'];
                if ($current->stripe_connect_account_id) {
                    $attributes['stripe_connect_status'] = $this->gateway
                        ->retrieveAccount($current->stripe_connect_account_id)->status();
                }
                if ($current->stripe_identity_session_id) {
                    $attributes['identity_status'] = $this->gateway
                        ->retrieveIdentity($current->stripe_identity_session_id)->status();
                    $attributes['identity_verified_at'] = $attributes['identity_status'] === 'verified'
                        ? ($current->identity_verified_at ?? now()) : null;
                }
                // Both reads must succeed before either status is persisted.
                DB::transaction(function () use ($user, $current, $attributes, $assertOwned): void {
                    $locked = User::query()->lockForUpdate()->findOrFail($user->id);
                    $assertOwned();
                    $this->access->authorize($locked);
                    if ($locked->stripe_connect_account_id !== $current->stripe_connect_account_id
                        || $locked->stripe_identity_session_id !== $current->stripe_identity_session_id) {
                        throw new OwnerOnboardingException;
                    }
                    $locked->update($attributes);
                });
            });
            $user->refresh();
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (Throwable) {
            throw new OwnerOnboardingException;
        }
    }

    /** @return array{url: string, session_id: ?string} */
    public function startIdentity(User $user, ?string $draftId = null): array
    {
        try {
            return $this->lock->run($user->id, function (Closure $assertOwned) use ($user, $draftId): array {
                $current = $user->fresh();
                $this->access->authorize($current, $draftId);
                if (! $current->stripe_identity_session_id && $current->identity_status === 'verified') {
                    return ['url' => $this->access->destination($draftId), 'session_id' => null];
                }
                $session = $current->stripe_identity_session_id
                    ? $this->gateway->retrieveIdentity($current->stripe_identity_session_id)
                    : null;
                if ($session === null || $session->stripeStatus === 'canceled') {
                    $session = $this->gateway->createIdentity((string) $current->id, url('/dashboard/profile'));
                }
                $status = $session->status();
                $url = in_array($session->stripeStatus, ['verified', 'processing'], true)
                    ? $this->access->destination($draftId) : $session->url;
                if (! is_string($url) || $url === '') {
                    throw new OwnerOnboardingException;
                }

                DB::transaction(function () use ($user, $current, $session, $status, $draftId, $assertOwned): void {
                    $locked = User::query()->lockForUpdate()->findOrFail($user->id);
                    $assertOwned();
                    $this->access->authorize($locked, $draftId);
                    if ($locked->stripe_identity_session_id !== $current->stripe_identity_session_id) {
                        throw new OwnerOnboardingException;
                    }
                    $locked->update([
                        'stripe_identity_session_id' => $session->id,
                        'identity_status' => $status,
                        'identity_verified_at' => $status === 'verified' ? ($locked->identity_verified_at ?? now()) : null,
                    ]);
                });

                return ['url' => $url, 'session_id' => $session->id];
            });
        } catch (AuthorizationException|ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            throw new OwnerOnboardingException;
        }
    }
}
