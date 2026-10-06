<?php

namespace App\Features\Payment\Contracts;

use App\Models\Group;
use App\Models\User;

interface PaymentGatewayInterface
{
    public function isAccountActive(User $user): bool;

    public function refundPayment(string $paymentIntentId, ?int $amountInCents = null, ?string $idempotencyKey = null): array;

    public function createMonthlyPrice(Group $group, int $amountInCents): string;

    public function createProduct(Group $group): array;

    public function updateSubscriptionItemPrice(string $itemId, string $newPriceId): void;

    public function cancelSubscription(string $stripeSubscriptionId): void;
}
