<?php

namespace App\Features\Payment\Services;

use App\Features\Payment\Contracts\OwnerStripeGatewayInterface;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Models\Group;
use App\Models\User;
use Stripe\StripeClient;

class StripeGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly OwnerStripeGatewayInterface $ownerGateway,
    ) {}

    public function refundPayment(string $paymentIntentId, ?int $amountInCents = null, ?string $idempotencyKey = null): array
    {
        $params = ['payment_intent' => $paymentIntentId, 'reverse_transfer' => true];

        if ($amountInCents !== null) {
            $params['amount'] = $amountInCents;
        }

        $refund = $this->stripe->refunds->create($params, [
            'idempotency_key' => $idempotencyKey ?? hash('sha256', 'refund:'.$paymentIntentId.':'.($amountInCents ?? 'full')),
        ]);

        return [
            'refund_id' => $refund->id,
            'status' => $refund->status,
            'amount' => $refund->amount,
        ];
    }

    public function cancelSubscription(string $stripeSubscriptionId): void
    {
        $current = $this->stripe->subscriptions->retrieve($stripeSubscriptionId);
        if ($current->status === 'canceled') {
            return;
        }
        $canceled = $this->stripe->subscriptions->cancel($stripeSubscriptionId);
        if ($canceled->status !== 'canceled') {
            throw new BillingUnavailable;
        }
    }

    public function createProduct(Group $group): array
    {
        $product = $this->stripe->products->create([
            'name' => $group->name.' — Equitab',
        ]);

        return ['product_id' => $product->id];
    }

    public function createMonthlyPrice(Group $group, int $amountInCents): string
    {
        $price = $this->stripe->prices->create([
            'unit_amount' => $amountInCents,
            'currency' => 'cad',
            'recurring' => ['interval' => 'month'],
            'product' => $group->stripePrice->stripe_product_id,
        ]);

        return $price->id;
    }

    public function updateSubscriptionItemPrice(string $itemId, string $newPriceId): void
    {
        $this->stripe->subscriptionItems->update($itemId, [
            'price' => $newPriceId,
            'proration_behavior' => 'none',
        ]);
    }

    public function isAccountActive(User $user): bool
    {
        if (! $user->stripe_connect_account_id) {
            return false;
        }

        return $this->ownerGateway->retrieveAccount($user->stripe_connect_account_id)->status() === 'active';
    }
}
