<?php

namespace App\Features\Payment\Services;

use App\Features\Payment\Contracts\BillingReadGatewayInterface;
use App\Features\Payment\Contracts\CheckoutGatewayInterface;
use Stripe\StripeClient;

final class StripeBillingGateway implements BillingReadGatewayInterface, CheckoutGatewayInterface
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function retrieveSubscription(string $id): array
    {
        return $this->stripe->subscriptions->retrieve($id, ['expand' => ['latest_invoice.confirmation_secret']])->toArray();
    }

    public function retrieveInvoice(string $id): array
    {
        return $this->stripe->invoices->retrieve($id)->toArray();
    }

    public function retrievePaymentIntent(string $id): array
    {
        return $this->stripe->paymentIntents->retrieve($id, ['expand' => ['latest_charge']])->toArray();
    }

    public function retrieveRefund(string $id): array
    {
        return $this->stripe->refunds->retrieve($id)->toArray();
    }

    public function invoicePayments(string $invoiceId): array
    {
        $payments = [];
        foreach ($this->stripe->invoicePayments->all(['invoice' => $invoiceId, 'status' => 'paid', 'limit' => 100])->autoPagingIterator() as $payment) {
            $payments[] = $payment->toArray();
        }

        return $payments;
    }

    public function createCustomer(array $parameters, string $key): string
    {
        return $this->stripe->customers->create($parameters, ['idempotency_key' => $key])->id;
    }

    public function attachPaymentMethod(string $methodId, string $customerId): void
    {
        $method = $this->stripe->paymentMethods->retrieve($methodId);
        if ($method->customer === $customerId) {
            return;
        }
        if ($method->customer !== null) {
            throw new BillingUnavailable;
        }
        $this->stripe->paymentMethods->attach($methodId, ['customer' => $customerId]);
    }

    public function createPrice(array $parameters, string $key): string
    {
        return $this->stripe->prices->create($parameters, ['idempotency_key' => $key])->id;
    }

    public function createSubscription(array $parameters, string $key): array
    {
        return $this->stripe->subscriptions->create($parameters, ['idempotency_key' => $key])->toArray();
    }
}
