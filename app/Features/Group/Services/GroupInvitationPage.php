<?php

namespace App\Features\Group\Services;

use App\Models\Group;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class GroupInvitationPage
{
    public function __construct(private readonly GroupAccess $access) {}

    public function find(string $token): Group
    {
        return Group::where('invite_token', $token)
            ->whereIn('status', ['open', 'full'])
            ->whereIn('visibility', GroupVisibility::RESTRICTED)
            ->whereHas('owner')
            ->with(['owner', 'subscription'])
            ->firstOrFail();
    }

    public function props(Group $group, ?User $user): array
    {
        return [
            'inviteToken' => $group->invite_token,
            'accessState' => $this->accessState($group, $user),
            'continueUrl' => route('invite.continue', $group->invite_token, absolute: false),
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'subscriptionName' => $group->subscription->name,
                'subscriptionSlug' => $group->subscription->slug,
                'description' => $group->description,
                'ownerName' => $group->owner->display_name,
                'ownerTrustScore' => $group->owner->calculateTrustScore(),
                'pricePerMember' => $group->calculatePricePerMemberIfJoined(),
                'currency' => $group->currency,
                'spotsAvailable' => max(0, $group->max_members - $group->current_members),
                'maxMembers' => $group->max_members,
            ],
        ];
    }

    private function accessState(Group $group, ?User $user): string
    {
        if ($user && $this->access->canUseService($user, $group)) {
            return $group->owner_id === $user->id ? 'owner' : 'member';
        }
        $member = $user ? $group->members()->where('user_id', $user->id)->first() : null;
        // A member with a reserved seat can finish paying even when the group is full.
        if (($group->isFull() || $group->status === 'full') && $member?->status !== 'pending_payment') {
            return 'full';
        }
        if (! $user) {
            return $group->subscription?->is_active ? 'guest' : 'unavailable';
        }
        if (! $group->subscription?->is_active && ! $user->hasVerifiedEmail()) {
            return 'unavailable';
        }
        if (! $user->hasVerifiedEmail()) {
            return 'verify_email';
        }
        try {
            $this->access->authorizeSubscription($user, $group, $group->invite_token, $member);
        } catch (HttpExceptionInterface) {
            return 'unavailable';
        }

        return 'checkout';
    }
}
