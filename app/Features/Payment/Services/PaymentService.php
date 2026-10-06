<?php

namespace App\Features\Payment\Services;

use App\Models\Group;
use App\Models\Payment;
use App\Models\User;

// Checkout, entitlement and refunds have separate responsibilities.
class PaymentService
{
    public function __construct(
        private readonly SubscriptionCheckoutService $checkout,
        private readonly PaymentSynchronizationService $synchronization,
        private readonly PaymentRefundService $refunds,
    ) {}

    public function initiateSubscription(User $payer, Group $group, string $paymentMethodId, ?string $inviteToken = null): array
    {
        $raw = $this->checkout->start($payer, $group, $paymentMethodId, $inviteToken);
        $this->synchronizeSubscription($raw['id']);
        $invoice = is_array($raw['latest_invoice'] ?? null) ? $raw['latest_invoice'] : [];

        return [
            'subscription_id' => $raw['id'], 'status' => $raw['status'],
            'client_secret' => $invoice['confirmation_secret']['client_secret'] ?? $invoice['payment_intent']['client_secret'] ?? null,
            'amount_today' => $invoice['amount_due'] ?? 0,
            'invoice_paid' => ($invoice['status'] ?? null) === 'paid',
            'next_billing_date' => $raw['items']['data'][0]['current_period_end'] ?? $raw['current_period_end'] ?? null,
        ];
    }

    public function synchronizeSubscription(string $subscriptionId, ?string $invoiceId = null): ?Payment
    {
        return $this->synchronization->synchronize($subscriptionId, $invoiceId);
    }

    public function refundPayment(Payment $payment, string $reason): Payment
    {
        return $this->refunds->refund($payment, $reason);
    }

    public function synchronizeRefund(string $paymentIntentId): void
    {
        $this->refunds->synchronizeIntent($paymentIntentId);
    }
}
