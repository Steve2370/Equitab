<?php

namespace App\Features\Group\Services;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class GroupAccess
{
    public function hasValidInvitation(Group $group, ?string $token): bool
    {
        return ! $group->trashed()
            && $group->subscription?->is_active
            && in_array($group->status, ['open', 'full'], true)
            && in_array($group->visibility, GroupVisibility::RESTRICTED, true)
            && filled($token) && filled($group->invite_token)
            && hash_equals($group->invite_token, $token);
    }

    public function canView(?User $user, Group $group): bool
    {
        if ($group->trashed()) {
            return false;
        }
        if ($group->visibility === 'public' && $group->subscription?->is_active) {
            return true;
        }

        return $user && $user->canAccessAccount() && $user->hasVerifiedEmail()
            && ($group->owner_id === $user->id
                || $group->members()->where('user_id', $user->id)
                    ->whereNull('cancellation_requested_at')
                    ->whereIn('status', ['active', 'pending_payment'])->exists());
    }

    public function authorizeSubscription(User $user, Group $group, ?string $inviteToken, ?GroupMember $member): void
    {
        abort_unless($user->canAccessAccount() && $user->hasVerifiedEmail(), 403, 'Compte non autorisé.');
        abort_if($group->trashed(), 404);
        abort_if($group->owner_id === $user->id, 403, 'Vous êtes le propriétaire de ce groupe.');
        abort_unless(in_array($group->status, ['open', 'full'], true), 403, 'Ce groupe est fermé.');
        // Retired offers accept no new commitment. Keep durable attempts/subscriptions
        // resumable: retirement must not strand a payment already sent to Stripe.
        $existingCommitment = $member && ($member->stripe_subscription_id
            || DB::table('subscription_attempts')->where('group_id', $group->id)->where('user_id', $user->id)->exists());
        abort_unless($group->subscription?->is_active || $existingCommitment, 403, 'Ce service ne propose plus de nouvelles adhésions.');
        abort_if($member && (in_array($member->status, ['left', 'kicked'], true) || $member->cancellation_requested_at), 403, 'Cette adhésion est terminée.');
        $admitted = $member && in_array($member->status, ['active', 'pending_payment'], true);
        if ($group->visibility !== 'public' && ! $admitted) {
            abort_unless($this->hasValidInvitation($group, $inviteToken), 403, 'Une invitation valide est requise.');
        }
        abort_if(! $admitted && ($group->status !== 'open' || $group->isFull()), 409, 'Ce groupe est complet.');
    }

    public function canUseService(User $user, Group $group): bool
    {
        if (! $user->canAccessAccount() || ! $user->hasVerifiedEmail() || $group->trashed()) {
            return false;
        }
        if ($group->owner_id === $user->id) {
            return true;
        }
        if (! in_array($group->status, ['open', 'full'], true)) {
            return false;
        }
        $member = $group->members()->where('user_id', $user->id)->first();

        return $member && $member->status === 'active' && ! $member->cancellation_requested_at && ! $this->hasRefundStarted($member)
            && (! $member->stripe_subscription_id || ($member->subscription_status === 'active' && $member->current_period_end !== null))
            && ($member->current_period_end === null || $member->current_period_end->isFuture());
    }

    public function hasRefundStarted(GroupMember $member): bool
    {
        return DB::table('payment_refund_attempts')->join('payments', 'payments.id', '=', 'payment_refund_attempts.payment_id')
            ->where('payments.group_id', $member->group_id)->where('payments.user_id', $member->user_id)->exists();
    }

    public function canChat(User $user, Group $group, User $other): bool
    {
        // Conversations are exclusively between this group's owner and a
        // currently entitled member; neither peer is trusted from the URL.
        return $user->id !== $other->id
            && in_array($group->owner_id, [$user->id, $other->id], true)
            && $this->canUseService($user, $group)
            && $this->canUseService($other, $group);
    }
}
