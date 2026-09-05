<?php

namespace App\Features\Payment\Services;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\User;
use App\Features\Payment\Repositories\Contracts\PaymentRepositoryInterface;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Jobs\CheckCredentialsProvided;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class PaymentService
{
    private const PLATFORM_FEE_PERCENTAGE = 0.05;

    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly PaymentRepositoryInterface $paymentRepository,
    ) {}

    public function initiateSubscription(
        User $payer,
        Group $group,
        string $paymentMethodId
    ): array {
        if ($group->isFull()) {
            throw new Exception('Ce groupe est complet.');
        }

        if (! $this->gateway->isAccountActive($group->owner)) {
            throw new Exception('Le propriétaire n\'a pas configuré ses paiements.');
        }

        $currentActiveMembers = $group->members()->where('status', 'active')->count();
        $futureActiveMembers = $currentActiveMembers + 1;
        $currentPricePerMember = (int) round($group->total_price / $futureActiveMembers);

        $platformFee = (int) round($currentPricePerMember * self::PLATFORM_FEE_PERCENTAGE);
        $stripePriceId = $this->gateway->createMonthlyPrice($group, $currentPricePerMember);

        $result = $this->gateway->createSubscription(
            payer: $payer,
            stripePriceId: $stripePriceId,
            stripeConnectAccountId: $group->owner->stripe_connect_account_id,
            platformFeeInCents: $platformFee,
            paymentMethodId: $paymentMethodId,
        );

        $isNewMember = ! $group->members()->where('user_id', $payer->id)->exists();

        $member = $group->members()->updateOrCreate(
            ['user_id' => $payer->id],
            [
                'role' => 'member',
                'status' => 'pending_payment',
                'share_amount' => $currentPricePerMember,
                'joined_at' => now(),
                'stripe_subscription_id' => $result['subscription_id'],
                'stripe_subscription_item_id' => $result['subscription_item_id'] ?? null,
                'stripe_customer_id' => $payer->fresh()->stripe_customer_id,
                'subscription_status' => $result['status'],
                'current_period_end' => now()->addMonth()->startOfMonth(),
            ]
        );

        if ($isNewMember) {
            $group->increment('current_members');
        }

        // Quand Stripe confirme la souscription tout de suite (pas de 3DS,
        // paiement synchrone réussi via error_if_incomplete), on active le
        // membre et on enregistre le paiement immédiatement — sans ça,
        // l'activation dépendait uniquement du webhook Stripe (pas toujours
        // joignable en local/dev) ou d'un second appel front-end dont
        // l'échec n'était jamais remonté à l'utilisateur, laissant le
        // membre bloqué "en attente" malgré un paiement Stripe réussi.
        if (($result['status'] ?? null) === 'active') {
            $this->activateMemberAndRecordPayment(
                member: $member,
                amountPaid: $result['amount_today'] ?? 0,
                currency: strtoupper($group->subscription->currency ?? 'cad'),
                paymentIntentId: $result['payment_intent_id'] ?? null,
            );
        }

        return $result;
    }

    /**
     * Active un membre et enregistre son paiement de façon idempotente.
     * Point d'entrée partagé par le flux de souscription synchrone, la
     * confirmation 3DS côté client (confirmSubscription) et le webhook
     * Stripe — pour que ces trois chemins ne puissent plus diverger.
     */
    public function activateMemberAndRecordPayment(
        GroupMember $member,
        int $amountPaid,
        string $currency = 'CAD',
        ?string $paymentIntentId = null,
    ): Payment {
        return DB::transaction(function () use ($member, $amountPaid, $currency, $paymentIntentId) {
            $member->update([
                'status' => 'active',
                'subscription_status' => 'active',
                'last_payment_at' => now(),
                'next_payment_at' => now()->addMonth(),
            ]);

            $existing = $paymentIntentId
                ? Payment::where('stripe_payment_intent_id', $paymentIntentId)->first()
                : Payment::where('group_id', $member->group_id)
                    ->where('user_id', $member->user_id)
                    ->where('status', 'completed')
                    ->whereDate('paid_at', today())
                    ->first();

            if ($existing) {
                return $existing;
            }

            $member->user->increment('completed_payments_count');

            $payment = Payment::create([
                'group_id' => $member->group_id,
                'user_id' => $member->user_id,
                'amount' => $amountPaid,
                'currency' => $currency,
                'status' => 'completed',
                'paid_at' => now(),
                'due_date' => now(),
                'period_start' => now()->startOfMonth(),
                'period_end' => now()->endOfMonth(),
                'platform_fee_amount' => (int) round($amountPaid * self::PLATFORM_FEE_PERCENTAGE),
                'stripe_payment_intent_id' => $paymentIntentId,
            ]);

            CheckCredentialsProvided::dispatch(
                paymentId: $payment->id,
                groupId: $member->group_id,
                userId: $member->user_id,
            )->delay(now()->addHours(48));

            Log::info('Paiement enregistré et membre activé', [
                'payment_id' => $payment->id,
                'group_id' => $member->group_id,
                'user_id' => $member->user_id,
                'amount' => $amountPaid,
            ]);

            return $payment;
        });
    }


    public function markPaymentFailed(string $stripePaymentIntentId, string $reason = ''): ?Payment
    {
        $payment = Payment::where('stripe_payment_intent_id', $stripePaymentIntentId)->first();

        if (! $payment) {
            return null;
        }

        $payment->update([
            'status' => 'failed',
        ]);

        $payment->increment('retry_count');

        return $payment;
    }
}
