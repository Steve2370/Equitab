<?php

namespace App\Features\Payment\Services;

use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Models\GroupMember;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SubscriptionCancellationService
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly BillingOperationLock $lock,
        private readonly MembershipState $memberships,
    ) {}

    public function request(GroupMember $member): bool
    {
        return $this->lock->run('group:'.$member->group_id, function (Closure $assertOwned) use ($member): bool {
            $current = DB::transaction(function () use ($member, $assertOwned): GroupMember {
                $current = GroupMember::lockForUpdate()->findOrFail($member->id);
                $assertOwned();
                $current->update(['cancellation_requested_at' => $current->cancellation_requested_at ?? now()]);
                $terminal = in_array($current->subscription_status, ['canceled', 'incomplete_expired'], true);
                $status = $terminal ? $current->subscription_status : ($current->stripe_subscription_id ? 'cancellation_pending' : 'canceled');
                $this->memberships->revoke($current, $status, true);

                return $current;
            });
            if (in_array($current->subscription_status, ['canceled', 'incomplete_expired'], true)) {
                return true;
            }
            try {
                $this->gateway->cancelSubscription($current->stripe_subscription_id);
                $assertOwned();
            } catch (Throwable) {
                Log::warning('Annulation Stripe à reprendre.', ['member_id' => $current->id]);

                return false;
            }
            $current->update(['subscription_status' => 'canceled']);

            return true;
        });
    }
}
