<?php

namespace App\Features\Payment\Services;

use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;

class StripeService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    public function createCustomer(string $email, string $name): Customer
    {
        return Customer::create([
            'email' => $email,
            'name' => $name,
        ]);
    }

    public function createPaymentIntent(
        int $amountInCents,
        string $currency = 'cad',
        string $customerId = null,
        array $metadata = []
    ): PaymentIntent {
        return PaymentIntent::create([
            'amount' => $amountInCents,
            'currency' => $currency,
            'customer' => $customerId,
            'metadata' => $metadata,
            'automatic_payment_methods' => ['enabled' => true],
        ]);
    }

    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return PaymentIntent::retrieve($paymentIntentId);
    }

    public function constructWebhookEvent(string $payload, string $sigHeader): \Stripe\Event
    {
        return \Stripe\Webhook::constructEvent(
            $payload,
            $sigHeader,
            config('services.stripe.webhook_secret')
        );
    }
}
