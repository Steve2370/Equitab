<?php

namespace App\Features\Payment\Services;

use App\Features\Payment\Contracts\OwnerStripeGatewayInterface;
use App\Features\Payment\DTO\OwnerConnectState;
use App\Features\Payment\DTO\OwnerIdentityState;
use Stripe\StripeClient;
use Throwable;

final class StripeOwnerGateway implements OwnerStripeGatewayInterface
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function createAccount(array $parameters, string $idempotencyKey): string
    {
        try {
            $id = $this->stripe->accounts->create($parameters, ['idempotency_key' => $idempotencyKey])->id;
            if (! is_string($id) || $id === '') {
                throw new OwnerOnboardingException;
            }

            return $id;
        } catch (Throwable) {
            throw new OwnerOnboardingException;
        }
    }

    public function createAccountLink(string $accountId, string $returnUrl, string $refreshUrl): string
    {
        try {
            $url = $this->stripe->accountLinks->create([
                'account' => $accountId,
                'return_url' => $returnUrl,
                'refresh_url' => $refreshUrl,
                'type' => 'account_onboarding',
            ])->url;
            if (! is_string($url) || $url === '') {
                throw new OwnerOnboardingException;
            }

            return $url;
        } catch (Throwable) {
            throw new OwnerOnboardingException;
        }
    }

    public function retrieveAccount(string $accountId): OwnerConnectState
    {
        try {
            $account = $this->stripe->accounts->retrieve($accountId);
            if (($account->id ?? null) !== $accountId || ! is_bool($account->charges_enabled ?? null) || ! is_bool($account->payouts_enabled ?? null)) {
                throw new OwnerOnboardingException;
            }

            return new OwnerConnectState(
                $account->id,
                $account->charges_enabled,
                $account->payouts_enabled,
                (bool) ($account->details_submitted ?? false),
                ! empty($account->requirements->pending_verification ?? []),
                $account->requirements->disabled_reason ?? null,
                ! empty($account->requirements->past_due ?? []),
            );
        } catch (Throwable) {
            throw new OwnerOnboardingException;
        }
    }

    public function retrieveIdentity(string $sessionId): OwnerIdentityState
    {
        try {
            $session = $this->stripe->identity->verificationSessions->retrieve($sessionId);
            if ($session->id !== $sessionId) {
                throw new OwnerOnboardingException;
            }

            $state = new OwnerIdentityState($session->id, $session->status, $session->url);
            $state->status();

            return $state;
        } catch (Throwable) {
            throw new OwnerOnboardingException;
        }
    }

    public function createIdentity(string $userId, string $returnUrl): OwnerIdentityState
    {
        try {
            $session = $this->stripe->identity->verificationSessions->create([
                'type' => 'document',
                'metadata' => ['user_id' => $userId],
                'options' => ['document' => ['require_matching_selfie' => true]],
                'return_url' => $returnUrl,
            ]);
            if (! is_string($session->id) || $session->id === '' || ! is_string($session->url) || $session->url === '') {
                throw new OwnerOnboardingException;
            }

            $state = new OwnerIdentityState($session->id, $session->status, $session->url);
            $state->status();

            return $state;
        } catch (Throwable) {
            throw new OwnerOnboardingException;
        }
    }
}
