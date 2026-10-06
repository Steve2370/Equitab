<?php

namespace App\Features\Payment\Services;

use App\Models\Group;
use App\Models\GroupMember;

final class MembershipState
{
    // Caller owns the group lock and a short SQL transaction. Suspended
    // subscriptions retain their seat; terminal departures release it once.
    public function revoke(GroupMember $member, string $subscriptionStatus, bool $terminal): void
    {
        $occupied = in_array($member->status, ['active', 'pending_payment', 'suspended'], true);
        $member->update([
            'status' => $terminal ? 'left' : ($member->status === 'pending_payment' ? 'pending_payment' : 'suspended'),
            'subscription_status' => $subscriptionStatus,
        ]);
        if ($terminal && $occupied) {
            $group = Group::withTrashed()->lockForUpdate()->findOrFail($member->group_id);
            $group->update(['current_members' => max(0, $group->current_members - 1)]);
            if ($group->status === 'full' && ! $group->isFull()) {
                $group->update(['status' => 'open']);
            }
        }
    }
}
