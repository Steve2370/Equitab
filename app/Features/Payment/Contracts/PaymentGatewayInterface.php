<?php

namespace App\Features\Payment\Contracts;

use App\Models\User;
use App\Models\Group;

interface PaymentGatewayInterface
{

    public function createConnectedAccount(User $user): string;

    public function createOnboardingLink(User $user, string $returnUrl, string $refreshUrl): string;

    public function isAccountActive(User $user): bool;

    public function refundPayment(string $paymentIntentId, ?int $amountInCents = null): array;

    public function createMonthlyPrice(Group $group, int $amountInCents): string;

    public function createProduct(Group $group): array;

    public function updateSubscriptionItemPrice(string $itemId, string $newPriceId): void;

    public function createPaymentIntent(
        User $payer,
        User $receiver,
        int $amountInCents,
        int $platformFeeInCents,
        string $currency,
        array $metadata = []
    ): array;

    public function createProductAndPrice(Group $group): array;

    public function createSubscription(
        User $payer,
        string $stripePriceId,
        string $stripeConnectAccountId,
        int $platformFeeInCents,
        string $paymentMethodId
    ): array;

    public function cancelSubscription(string $stripeSubscriptionId): void;

    public function createIdentityVerificationSession(User $user): array;

    public function verifyWebhookSignature(string $payload, string $signature): bool;
}
