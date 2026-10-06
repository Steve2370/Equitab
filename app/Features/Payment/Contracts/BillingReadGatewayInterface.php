<?php

namespace App\Features\Payment\Contracts;

interface BillingReadGatewayInterface
{
    public function retrieveSubscription(string $id): array;

    public function retrieveInvoice(string $id): array;

    public function retrievePaymentIntent(string $id): array;

    public function retrieveRefund(string $id): array;

    public function invoicePayments(string $invoiceId): array;
}
