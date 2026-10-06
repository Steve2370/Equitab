<?php

namespace App\Features\Payment\Services;

use App\Features\Payment\Contracts\BillingReadGatewayInterface;
use App\Mail\PaymentFailed;
use App\Models\GroupMember;
use App\Models\StripeEvent;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class StripeEventProcessor
{
    private const OBJECT_TYPES = [
        'invoice.paid' => 'invoice',
        'invoice.payment_succeeded' => 'invoice',
        'invoice.payment_failed' => 'invoice',
        'invoice_payment.paid' => 'invoice_payment',
        'customer.subscription.updated' => 'subscription',
        'customer.subscription.deleted' => 'subscription',
        'refund.created' => 'refund',
        'refund.updated' => 'refund',
        'refund.failed' => 'refund',
        'charge.refunded' => 'charge',
        'identity.verification_session.verified' => 'identity.verification_session',
        'identity.verification_session.processing' => 'identity.verification_session',
        'identity.verification_session.requires_input' => 'identity.verification_session',
        'identity.verification_session.canceled' => 'identity.verification_session',
    ];

    public function __construct(
        private readonly PaymentService $payments,
        private readonly BillingReadGatewayInterface $billing,
        private readonly OwnerIdentityWebhookService $identity,
        private readonly OwnerConnectWebhookService $connect,
    ) {}

    /** @param array<string, mixed> $event Signature must have been verified. */
    public function process(array $event): void
    {
        $eventId = $this->id($event['id'] ?? null, 'evt');
        $type = $event['type'] ?? null;
        if (($event['object'] ?? null) !== 'event' || ! is_string($type) || $type === '' || strlen($type) > 255) {
            throw new RuntimeException('Invalid Stripe event envelope.');
        }

        if ($type === 'account.updated') {
            $account = $this->object($event, 'account');
            $this->connect->synchronize($eventId, $this->id($account['id'] ?? null, 'acct'));

            return;
        }
        if (! isset(self::OBJECT_TYPES[$type])) {
            // Unsupported events do not activate anything and are not archived
            // with their customer data, payment secrets or Identity documents.
            return;
        }

        $object = $this->object($event, self::OBJECT_TYPES[$type]);
        $prefix = match (self::OBJECT_TYPES[$type]) {
            'invoice' => 'in', 'invoice_payment' => 'inpay', 'subscription' => 'sub',
            'refund' => 're', 'charge' => 'ch', 'identity.verification_session' => 'vs',
        };
        $objectId = $this->id($object['id'] ?? null, $prefix);
        $lease = Cache::store('database')->lock('stripe-event:'.$eventId, 300);
        if (! $lease->get()) {
            throw new RuntimeException('Stripe event already being processed.');
        }
        $deadline = microtime(true) + 299;
        $notification = null;

        try {
            if (StripeEvent::where('stripe_event_id', $eventId)->whereNotNull('processed_at')->exists()) {
                return;
            }

            // Domain operations reconcile current provider state and must be
            // idempotent: a crash can happen after their commit, before receipt.
            // Never keep a SQL transaction open during their remote requests.
            $notification = $this->handle($type, $object, $objectId, $eventId);

            DB::transaction(function () use ($lease, $deadline, $eventId, $type, $objectId): void {
                if (microtime(true) >= $deadline || ! $lease->isOwnedByCurrentProcess()) {
                    throw new RuntimeException('Stripe event lease expired.');
                }
                StripeEvent::updateOrCreate(['stripe_event_id' => $eventId], [
                    'type' => $type,
                    'payload' => ['object_id' => $objectId, 'object_type' => self::OBJECT_TYPES[$type]],
                    'processed_at' => now(),
                ]);
            });
        } finally {
            $lease->release();
        }

        $notification?->__invoke();
    }

    /** @param array<string, mixed> $object */
    private function handle(string $type, array $object, string $objectId, string $eventId): ?Closure
    {
        if (str_starts_with($type, 'identity.verification_session.')) {
            $recipient = $this->identity->synchronize($objectId);

            return $recipient === null ? null : fn () => $this->identity->notifyVerified($recipient, $eventId);
        }
        if (str_starts_with($type, 'customer.subscription.')) {
            $this->payments->synchronizeSubscription($objectId);

            return null;
        }
        if (str_starts_with($type, 'refund.') || $type === 'charge.refunded') {
            $this->payments->synchronizeRefund($this->id($object['payment_intent'] ?? null, 'pi'));

            return null;
        }

        $invoice = $object;
        if ($type === 'invoice_payment.paid') {
            $invoiceId = $this->id($object['invoice'] ?? null, 'in');
            $invoice = $this->billing->retrieveInvoice($invoiceId);
            if (($invoice['object'] ?? null) !== 'invoice' || $this->id($invoice['id'] ?? null, 'in') !== $invoiceId) {
                throw new RuntimeException('Invoice reference mismatch.');
            }
        }
        $invoiceId = $this->id($invoice['id'] ?? null, 'in');
        $subscriptionId = $this->invoiceSubscription($invoice);
        if ($subscriptionId === null) {
            return null; // Explicit standalone invoice, not a missing schema.
        }

        $member = $type === 'invoice.payment_failed'
            ? GroupMember::where('stripe_subscription_id', $subscriptionId)->first() : null;
        $wasActive = $member?->status === 'active';
        $this->payments->synchronizeSubscription($subscriptionId, $invoiceId);

        if ($wasActive && $member->fresh()?->status === 'suspended') {
            return fn () => $this->notifyPaymentFailed($member, $eventId);
        }

        return null;
    }

    /** @param array<string, mixed> $invoice */
    private function invoiceSubscription(array $invoice): ?string
    {
        $legacy = isset($invoice['subscription']) ? $this->id($invoice['subscription'], 'sub') : null;
        if (array_key_exists('parent', $invoice)) {
            $parent = $invoice['parent'];
            if ($parent === null) {
                if ($legacy !== null) {
                    throw new RuntimeException('Conflicting invoice subscription references.');
                }

                return null;
            }
            if (! is_array($parent)) {
                throw new RuntimeException('Invalid invoice parent.');
            }
            if (($parent['type'] ?? null) === 'quote_details') {
                if ($legacy !== null) {
                    throw new RuntimeException('Conflicting invoice subscription references.');
                }

                return null;
            }
            if (($parent['type'] ?? null) !== 'subscription_details') {
                throw new RuntimeException('Unknown invoice parent type.');
            }

            $subscriptionId = $this->id($parent['subscription_details']['subscription'] ?? null, 'sub');
            if ($legacy !== null && $legacy !== $subscriptionId) {
                throw new RuntimeException('Conflicting invoice subscription references.');
            }

            return $subscriptionId;
        }
        if (array_key_exists('subscription', $invoice)) {
            return $legacy;
        }

        throw new RuntimeException('Unsupported invoice subscription schema.');
    }

    private function id(mixed $value, string $prefix): string
    {
        $value = is_array($value) ? ($value['id'] ?? null) : $value;
        if (! is_string($value) || strlen($value) > 255 || ! preg_match('/^'.$prefix.'_[A-Za-z0-9_]+$/D', $value)) {
            throw new RuntimeException('Invalid Stripe reference.');
        }

        return $value;
    }

    /** @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function object(array $event, string $expected): array
    {
        $object = $event['data']['object'] ?? null;
        if (! is_array($object) || ($object['object'] ?? null) !== $expected) {
            throw new RuntimeException('Unsupported Stripe object schema.');
        }

        return $object;
    }

    private function notifyPaymentFailed(GroupMember $member, string $eventId): void
    {
        try {
            if ($member->user?->notif_payment_failed) {
                Mail::to($member->user->email)->send(new PaymentFailed($member->load('group.subscription')));
            }
        } catch (Throwable) {
            Log::warning('Courriel de paiement échoué non envoyé.', ['stripe_event_id' => $eventId]);
        }
    }
}
