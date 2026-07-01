<?php

namespace App\Jobs;

use App\Models\Group;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use App\Mail\PriceChanged;
use Illuminate\Support\Facades\Log;

class RecalculateGroupPrices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(PaymentGatewayInterface $gateway): void
    {
        $groups = Group::where('status', 'open')
            ->whereHas('members', fn($q) => $q->where('status', 'active'))
            ->get();

        foreach ($groups as $group) {
            $this->recalculateGroup($group, $gateway);
        }
    }

    private function recalculateGroup(Group $group, PaymentGatewayInterface $gateway): void
    {
        $newPricePerMember = $group->calculateCurrentPricePerMember();
        $activeMembers = $group->members()->where('status', 'active')->where('role', 'member')->get();

        if ($activeMembers->isEmpty()) {
            return;
        }

        try {
            $newPriceId = $gateway->createMonthlyPrice($group, $newPricePerMember);

            foreach ($activeMembers as $member) {
                if (! $member->stripe_subscription_item_id) {
                    continue;
                }

                $oldPrice = $member->share_amount;

                $gateway->updateSubscriptionItemPrice(
                    $member->stripe_subscription_item_id,
                    $newPriceId
                );

                $member->update(['share_amount' => $newPricePerMember]);

                if ($oldPrice !== $newPricePerMember) {
                    Mail::to($member->user->email)
                        ->send(new PriceChanged($member, $oldPrice, $newPricePerMember));
                }
            }

            Log::info("Prix recalculé pour groupe #{$group->id}: {$newPricePerMember}");

        } catch (\Exception $e) {
            Log::error("Échec recalcul prix groupe #{$group->id}: " . $e->getMessage());
        }
    }
}
