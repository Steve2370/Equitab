<?php

namespace App\Services\Payment;

use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\DB;
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

        return $result;
    }


    public function confirmPayment(string $stripePaymentIntentId): ?Payment
    {
        return DB::transaction(function () use ($stripePaymentIntentId) {
            $payment = Payment::where('stripe_payment_intent_id', $stripePaymentIntentId)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                return null;
            }

            if ($payment->status === 'completed') {
                return $payment;
            }

            $payment->update([
                'status' => 'completed',
                'paid_at' => now(),
            ]);

            $payment->group->members()
                ->where('user_id', $payment->user_id)
                ->update([
                    'status' => 'active',
                    'last_payment_at' => now(),
                    'next_payment_at' => $payment->group->renewal_date,
                ]);

            $payment->user->increment('completed_payments_count');

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
