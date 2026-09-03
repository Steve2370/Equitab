<?php

namespace App\Features\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GroupMember;
use App\Models\StripeEvent;
use App\Models\User;
use App\Models\Payment;
use App\Mail\IdentityVerified;
use Illuminate\Support\Facades\Mail;
use App\Mail\ConnectAccountActivated;
use App\Mail\PaymentFailed;
use App\Jobs\CheckCredentialsProvided;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Features\Payment\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly PaymentService $paymentService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        // Le header Stripe-Account n'est pas envoyé de façon fiable pour les
        // événements de comptes connectés avec les Destinations d'événements
        // (contrairement aux anciens webhooks Connect). On essaie donc les
        // deux secrets connus plutôt que de deviner lequel utiliser.
        $event = null;
        $lastError = null;

        foreach (array_filter([
            config('services.stripe.webhook_secret'),
            config('services.stripe.connect_webhook_secret'),
        ]) as $secret) {
            try {
                $event = Webhook::constructEvent($payload, $sigHeader, $secret);
                break;
            } catch (\Exception $e) {
                $lastError = $e;
            }
        }

        if (! $event) {
            Log::error('Webhook signature invalide: ' . ($lastError?->getMessage() ?? 'aucun secret configuré'));
            return response()->json(['error' => 'Webhook invalide.'], 400);
        }

        $alreadyProcessed = StripeEvent::where('stripe_event_id', $event->id)->exists();

        if ($alreadyProcessed) {
            return response()->json(['received' => true]);
        }

        StripeEvent::create([
            'stripe_event_id' => $event->id,
            'type' => $event->type,
            'payload' => $event->toArray(),
            'processed_at' => now(),
        ]);

        try {
            match ($event->type) {
                'payment_intent.succeeded' => $this->paymentService->confirmPayment($event->data->object->id),
                'invoice.paid' => $this->handleInvoicePaid($event->data->object),
                'invoice_payment.paid' => $this->handleInvoicePaymentPaid($event->data->object),
                'invoice.payment_failed' => $this->handleInvoicePaymentFailed($event->data->object),
                'customer.subscription.deleted' => $this->handleSubscriptionCanceled($event->data->object),
                'account.updated' => $this->handleAccountUpdated($event->data->object),
                'identity.verification_session.verified' => $this->handleIdentityVerified($event->data->object),
                'identity.verification_session.processing' => $this->handleIdentityProcessing($event->data->object),
                default => null,
            };
        } catch (\Exception $e) {
            Log::error('Erreur traitement webhook ' . $event->type . ': ' . $e->getMessage());
        }

        return response()->json(['received' => true]);
    }

    private function handleInvoicePaid(object $invoice): void
    {
        $subscriptionId = $invoice->subscription ?? null;

        Log::info('handleInvoicePaid', ['subscription_id' => $subscriptionId]);

        if (! $subscriptionId) return;

        $member = GroupMember::where('stripe_subscription_id', $subscriptionId)->first();

        if (! $member) {
            Log::warning('No GroupMember found for subscription: ' . $subscriptionId);
            return;
        }

        $this->activateMemberAndCreatePayment($member, $invoice->amount_paid ?? 0, $invoice->currency ?? 'cad', $invoice->payment_intent ?? null);
    }

    private function handleInvoicePaymentPaid(object $invoicePayment): void
    {
        $subscriptionId = $invoicePayment->subscription ?? null;

        Log::info('handleInvoicePaymentPaid', ['subscription_id' => $subscriptionId]);

        if (! $subscriptionId) return;

        $member = GroupMember::where('stripe_subscription_id', $subscriptionId)->first();

        if (! $member) {
            Log::warning('No GroupMember found for subscription (invoice_payment.paid): ' . $subscriptionId);
            return;
        }

        $this->activateMemberAndCreatePayment($member, $invoicePayment->amount_paid ?? 0, $invoicePayment->currency ?? 'cad', $invoicePayment->payment_intent ?? null);
    }

    private function activateMemberAndCreatePayment(GroupMember $member, int $amountPaid, string $currency, ?string $paymentIntentId): void
    {
        // Délègue au même point d'entrée que le flux de souscription
        // synchrone et confirmSubscription() — évite que ces trois chemins
        // d'activation divergent à nouveau (c'est cette divergence qui
        // laissait des membres bloqués "en attente" malgré un paiement
        // Stripe réussi). Idempotent via stripe_payment_intent_id.
        $this->paymentService->activateMemberAndRecordPayment(
            member: $member,
            amountPaid: $amountPaid,
            currency: strtoupper($currency),
            paymentIntentId: $paymentIntentId,
        );
    }

    private function handleInvoicePaymentFailed(object $invoice): void
    {
        $member = GroupMember::where('stripe_subscription_id', $invoice->subscription ?? '')->first();
        if (! $member) return;

        $member->update([
            'status' => 'suspended',
            'subscription_status' => 'past_due',
        ]);

        if ($member->user->notif_payment_failed) {
            Mail::to($member->user->email)->send(new PaymentFailed($member->load('group.subscription')));
        }
        Log::warning('Invoice payment failed for member #' . $member->user_id);
    }

    private function handleSubscriptionCanceled(object $subscription): void
    {
        $member = GroupMember::where('stripe_subscription_id', $subscription->id)->first();
        if (! $member) return;

        $member->update([
            'status' => 'left',
            'subscription_status' => 'canceled',
        ]);

        $member->group->decrement('current_members');
        Log::info('Subscription canceled for member #' . $member->user_id);
    }

    private function handleAccountUpdated(object $account): void
    {
        $user = User::where('stripe_connect_account_id', $account->id)->first();
        if (! $user) return;

        $wasActive = $user->stripe_connect_status === 'active';

        // Stripe peut activer charges_enabled avant d'avoir fini de vérifier
        // certains éléments en arrière-plan (compte bancaire, identité du
        // titulaire...). Tant que pending_verification n'est pas vide, le
        // compte est encore en cours de vérification, pas actif.
        $pendingVerification = ! empty($account->requirements->pending_verification ?? []);

        $status = match(true) {
            $pendingVerification => 'pending',
            $account->charges_enabled && $account->payouts_enabled => 'active',
            $account->details_submitted => 'pending',
            default => 'restricted',
        };

        $user->update(['stripe_connect_status' => $status]);

        if (! $wasActive && $status === 'active') {
            Mail::to($user->email)
                ->send(new ConnectAccountActivated($user));
        }

        Log::info('Stripe Connect status updated for user #' . $user->id . ': ' . $status);
    }

    private function handleIdentityVerified(object $session): void
    {
        $userId = $session->metadata->user_id ?? null;

        if (! $userId) {
            Log::warning('identity.verified: no user_id in metadata');
            return;
        }

        $user = User::find($userId);

        if (! $user) {
            Log::warning('identity.verified: user not found #' . $userId);
            return;
        }

        $user->update(['identity_status' => 'verified']);
        Mail::to($user->email)->send(new IdentityVerified($user));
        Log::info('Identity verified for user #' . $user->id);
    }

    private function handleIdentityProcessing(object $session): void
    {
        $userId = $session->metadata->user_id ?? null;
        if (! $userId) return;

        $user = User::find($userId);
        if (! $user) return;

        $user->update(['identity_status' => 'pending']);
        Log::info('Identity processing for user #' . $user->id);
    }
}
