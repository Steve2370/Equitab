<?php

namespace App\Features\Payment\Contracts;

use App\Features\Payment\DTO\OwnerConnectState;
use App\Features\Payment\DTO\OwnerIdentityState;

interface OwnerStripeGatewayInterface
{
    /** @param array<string, mixed> $parameters */
    public function createAccount(array $parameters, string $idempotencyKey): string;

    public function createAccountLink(string $accountId, string $returnUrl, string $refreshUrl): string;

    public function retrieveAccount(string $accountId): OwnerConnectState;

    public function retrieveIdentity(string $sessionId): OwnerIdentityState;

    public function createIdentity(string $userId, string $returnUrl): OwnerIdentityState;
}
