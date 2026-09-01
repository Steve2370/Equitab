<?php

namespace App\Features\Payment\Services;

use App\Models\User;
use App\Models\Group;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;
use Exception;

class StripeGateway implements PaymentGatewayInterface
{
    private StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret'));
    }

    public function refundPayment(string $paymentIntentId, ?int $amountInCents = null): array
    {
        $params = ['payment_intent' => $paymentIntentId];

        if ($amountInCents !== null) {
            $params['amount'] = $amountInCents;
        }

        $refund = $this->stripe->refunds->create($params);

        return [
            'refund_id' => $refund->id,
            'status' => $refund->status,
            'amount' => $refund->amount,
        ];
    }

    public function createConnectedAccount(User $user): string
    {
        try {
            $account = $this->stripe->accounts->create([
                'type' => 'express',
                'country' => 'CA',
                'email' => $user->email,
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ],
                'business_type' => 'individual',
            ]);

            $user->update(['stripe_connect_account_id' => $account->id]);

            return $account->id;
        } catch (ApiErrorException $e) {
            throw new Exception('Erreur Stripe Connect : ' . $e->getMessage());
        }
    }

    public function createOnboardingLink(User $user, string $returnUrl, string $refreshUrl): string
    {
        if (! $user->stripe_connect_account_id) {
            $this->createConnectedAccount($user);
            $user->refresh();
        }

        $accountLink = $this->stripe->accountLinks->create([
            'account' => $user->stripe_connect_account_id,
            'return_url' => $returnUrl,
            'refresh_url' => $refreshUrl,
            'type' => 'account_onboarding',
        ]);

        return $accountLink->url;
    }

    public function createProductAndPrice(Group $group): array
    {
        $product = $this->stripe->products->create([
            'name' => $group->name . ' — Equitab',
            'metadata' => ['group_id' => (string) $group->id],
        ]);

        $price = $this->stripe->prices->create([
            'product' => $product->id,
            'unit_amount' => $group->price_per_member,
            'currency' => strtolower($group->subscription->currency),
            'recurring' => ['interval' => 'month'],
        ]);

        return [
            'product_id' => $product->id,
            'price_id' => $price->id,
        ];
    }

    public function createSubscription(
        User $payer,
        string $stripePriceId,
        string $stripeConnectAccountId,
        int $platformFeeInCents,
        string $paymentMethodId
    ): array {
        if (! $payer->stripe_customer_id) {
            $customer = $this->stripe->customers->create([
                'email' => $payer->email,
                'name' => $payer->name,
                'payment_method' => $paymentMethodId,
                'invoice_settings' => [
                    'default_payment_method' => $paymentMethodId,
                ],
            ]);
            $payer->update(['stripe_customer_id' => $customer->id]);
        } else {
            $this->stripe->paymentMethods->attach($paymentMethodId, [
                'customer' => $payer->stripe_customer_id,
            ]);
            $this->stripe->customers->update($payer->stripe_customer_id, [
                'invoice_settings' => [
                    'default_payment_method' => $paymentMethodId,
                ],
            ]);
        }

        $subscription = $this->stripe->subscriptions->create([
            'customer' => $payer->stripe_customer_id,
            'items' => [['price' => $stripePriceId]],
            'application_fee_percent' => 5,
            'transfer_data' => ['destination' => $stripeConnectAccountId],
            'default_payment_method' => $paymentMethodId,
            'payment_behavior' => 'error_if_incomplete',
            'payment_settings' => [
                'save_default_payment_method' => 'on_subscription',
                'payment_method_types' => ['card'],
            ],
            'billing_cycle_anchor' => $this->getNextFirstOfMonth(),
            'proration_behavior' => 'create_prorations',
            'expand' => ['latest_invoice.payment_intent'],
        ]);

        $invoice = $subscription->latest_invoice;
        $paymentIntent = $invoice?->payment_intent;
        $clientSecret = $paymentIntent?->client_secret ?? null;

        return [
            'subscription_id' => $subscription->id,
            'subscription_item_id' => $subscription->items->data[0]->id ?? null,
            'status' => $subscription->status,
            'client_secret' => $paymentIntent?->client_secret ?? null,
            'amount_today' => $invoice?->amount_due ?? 0,
            'next_billing_date' => $this->getNextFirstOfMonth(),
        ];
    }

    public function cancelSubscription(string $stripeSubscriptionId): void
    {
        $this->stripe->subscriptions->cancel($stripeSubscriptionId);
    }

    public function createProduct(Group $group): array
    {
        $product = $this->stripe->products->create([
            'name' => $group->name . ' — Equitab',
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

        $account = $this->stripe->accounts->retrieve($user->stripe_connect_account_id);
        return $account->charges_enabled;
    }

    public function createPaymentIntent(
        User $payer,
        User $receiver,
        int $amountInCents,
        int $platformFeeInCents,
        string $currency,
        array $metadata = []
    ): array {
        if (! $payer->stripe_customer_id) {
            $customer = $this->stripe->customers->create([
                'email' => $payer->email,
                'name' => $payer->name,
            ]);
            $payer->update(['stripe_customer_id' => $customer->id]);
        }

        $intent = $this->stripe->paymentIntents->create([
            'amount' => $amountInCents,
            'currency' => strtolower($currency),
            'customer' => $payer->stripe_customer_id,
            'application_fee_amount' => $platformFeeInCents,
            'transfer_data' => [
                'destination' => $receiver->stripe_connect_account_id,
            ],
            'metadata' => $metadata,
            'automatic_payment_methods' => ['enabled' => true],
        ]);

        return [
            'payment_intent_id' => $intent->id,
            'client_secret' => $intent->client_secret,
        ];
    }

    private function getNextFirstOfMonth(): int
    {
        // Toujours le 1er du mois suivant, y compris si on rejoint pile le
        // 1er : dans ce cas, la période de prorata (aujourd'hui → 1er du
        // mois prochain) couvre un mois complet, donc le montant facturé
        // aujourd'hui est déjà 100% du prix mensuel. Pas de cas particulier
        // à gérer ici.
        $anchor = new \DateTime('first day of next month', new \DateTimeZone('UTC'));
        $anchor->setTime(0, 0, 0);

        return $anchor->getTimestamp();
    }

    public function createIdentityVerificationSession(User $user): array
    {
        $session = $this->stripe->identity->verificationSessions->create([
            'type' => 'document',
            'metadata' => [
                'user_id' => (string) $user->id,
            ],
            'options' => [
                'document' => [
                    'require_matching_selfie' => true,
                ],
            ],
            'return_url' => config('app.url') . '/dashboard/profile',
        ]);

        $user->update(['stripe_identity_session_id' => $session->id]);

        return ['url' => $session->url, 'session_id' => $session->id];
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        try {
            \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                config('services.stripe.webhook_secret')
            );
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
