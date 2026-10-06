<?php

namespace App\Features\Payment\Contracts;

interface CheckoutGatewayInterface
{
    public function createCustomer(array $parameters, string $key): string;

    public function attachPaymentMethod(string $methodId, string $customerId): void;

    public function createPrice(array $parameters, string $key): string;

    public function createSubscription(array $parameters, string $key): array;
}
