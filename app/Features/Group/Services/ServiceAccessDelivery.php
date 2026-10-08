<?php

namespace App\Features\Group\Services;

use App\Features\Payment\Services\BillingOperationLock;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ServiceAccessDelivery
{
    public function __construct(
        private readonly GroupAccess $access,
        private readonly ServiceInvitationUrl $urls,
        private readonly BillingOperationLock $lock,
    ) {}

    public function state(User $user, Group $group): array
    {
        abort_unless($user->canAccessAccount() && $user->hasVerifiedEmail(), 403);
        $member = $group->members()->where('user_id', $user->id)->first();
        abort_unless($group->owner_id === $user->id || $member, 404);
        $empty = ['status' => 'unavailable', 'mode' => $group->access_mode, 'credentials' => null, 'invitation' => null];
        if ($member && $this->access->hasRefundStarted($member)) {
            return $empty;
        }
        if (! $this->access->canUseService($user, $group)) {
            $pending = ! $group->trashed() && in_array($group->status, ['open', 'full'], true)
                && $member?->status === 'pending_payment' && ! $member->cancellation_requested_at;

            return [...$empty, 'status' => $pending ? 'payment_pending' : 'unavailable'];
        }
        if ($group->access_mode === 'invitation') {
            if (! $member || ! $this->invitationProvided($group, $member)) {
                return [...$empty, 'status' => 'awaiting_owner'];
            }

            return [...$empty, 'status' => 'ready', 'invitation' => [
                'url' => $member->service_invitation_url,
                'provided_at' => $member->service_invitation_provided_at->toIso8601String(),
                'channel' => $member->service_invitation_channel,
                'recipient_email' => $member->service_invitation_email,
            ]];
        }

        return [...$empty, 'status' => $this->credentialsProvided($group) ? 'ready' : 'awaiting_owner',
            'credentials' => $this->credentialsProvided($group) ? [
                'email' => $group->credential_email, 'password' => $group->credential_password, 'notes' => $group->credential_notes,
            ] : null];
    }

    public function credentialsProvided(Group $group): bool
    {
        return filled($group->credential_email) && filled($group->credential_password);
    }

    public function invitationProvided(Group $group, GroupMember $member): bool
    {
        return $member->service_invitation_provided_at !== null && $member->service_access_revoked_at === null
            && ($this->invitationChannel($group) === 'provider_email'
                ? $member->service_invitation_channel === 'provider_email' && filled($member->service_invitation_email)
                : $member->service_invitation_channel === 'link' && $this->urls->valid($group, $member->service_invitation_url));
    }

    public function invitationChannel(Group $group): ?string
    {
        return $group->access_mode !== 'invitation' ? null
            : ($group->subscription?->slug === 'bitwarden-families' ? 'provider_email' : 'link');
    }

    public function providedFor(Payment $payment, Group $group): bool
    {
        if ($group->access_mode === 'invitation') {
            $member = $group->members()->where('user_id', $payment->user_id)->first();

            // Delivery history survives a later cancellation/removal. Otherwise a
            // member could consume the access, leave, then obtain the 48 h refund.
            // These guarded fields are written only after validated owner delivery.
            return $member && $member->service_invitation_provided_at !== null
                && in_array($member->service_invitation_channel, ['link', 'provider_email'], true);
        }

        // Do not retroactively refund legacy payments under a newly tightened rule.
        return $payment->access_check_version < 2
            ? filled($group->credential_email) || filled($group->credential_password)
            : $this->credentialsProvided($group);
    }

    public function authorizeOwner(User $owner, Group $group): void
    {
        abort_unless($group->owner_id === $owner->id && $owner->canAccessAccount() && $owner->hasVerifiedEmail(), 403);
    }

    public function ownerState(User $owner, Group $group): array
    {
        $this->authorizeOwner($owner, $group);

        return [
            'id' => $group->id, 'name' => $group->name, 'mode' => $group->access_mode,
            'invitation_channel' => $this->invitationChannel($group),
            'closed' => $group->trashed() || ! in_array($group->status, ['open', 'full'], true),
            'credentials_ready' => $group->access_mode === 'credentials' && $this->credentialsProvided($group),
            'members' => $group->members()->where('role', 'member')->with('user')->get()->map(fn (GroupMember $member) => [
                'id' => $member->id, 'name' => $member->user?->display_name ?? 'Compte supprimé',
                'email' => $member->user?->email,
                'status' => $member->status,
                'provided_at' => $member->service_invitation_provided_at?->toIso8601String(),
                'revoked_at' => $member->service_access_revoked_at?->toIso8601String(),
                'revoke_required' => $this->revocationRequired($group, $member),
            ])->all(),
        ];
    }

    public function revocationRequired(Group $group, GroupMember $member): bool
    {
        return $member->service_invitation_provided_at && ! $member->service_access_revoked_at
            && ($group->trashed() || ! in_array($group->status, ['open', 'full'], true) || $member->cancellation_requested_at
                || in_array($member->status, ['left', 'kicked', 'suspended'], true)
                || ($member->current_period_end && ! $member->current_period_end->isFuture())
                || ! $member->user || ! $member->user->canAccessAccount() || ! $member->user->hasVerifiedEmail());
    }

    public function deliver(User $owner, Group $group, ?GroupMember $member, array $data): void
    {
        $this->lock->run('access-delivery:'.$group->id, function (Closure $assertOwned) use ($owner, $group, $member, $data): void {
            // Share the billing mutex with cancellation, closure and payment sync.
            // No SQL row lock may be held while waiting for that mutex.
            $this->lock->run('group:'.$group->id, function (Closure $assertGroupOwned) use ($owner, $group, $member, $data, $assertOwned): void {
                DB::transaction(function () use ($owner, $group, $member, $data, $assertOwned, $assertGroupOwned): void {
                    $current = Group::withTrashed()->lockForUpdate()->findOrFail($group->id);
                    $this->authorizeOwner($owner->fresh(), $current);
                    abort_if($current->trashed() || ! in_array($current->status, ['open', 'full'], true), 409, 'Ce groupe ne peut plus fournir d’accès.');
                    $assertOwned();
                    $assertGroupOwned();
                    if ($current->access_mode === 'credentials') {
                        abort_if($member !== null, 422);
                        $validated = validator($data, [
                            'credential_email' => ['required', 'email', 'max:255'],
                            'credential_password' => ['required', 'string', 'max:255'],
                            'credential_notes' => ['nullable', 'string', 'max:1000'],
                            'invitation_url' => ['prohibited'],
                        ])->validate();
                        $current->update($validated);

                        return;
                    }
                    abort_unless($member && $member->group_id === $current->id && $member->role === 'member', 404);
                    $target = GroupMember::lockForUpdate()->findOrFail($member->id);
                    abort_if($this->access->hasRefundStarted($target), 409, 'Un remboursement est déjà engagé pour cette adhésion.');
                    abort_unless(in_array($target->status, ['active', 'pending_payment'], true) && ! $target->cancellation_requested_at, 409, 'Cette adhésion ne peut plus recevoir d’invitation.');
                    abort_if($target->service_access_revoked_at !== null, 409, 'Cet accès a été retiré.');
                    foreach (['credential_email', 'credential_password', 'credential_notes'] as $key) {
                        if (filled($data[$key] ?? null)) {
                            throw ValidationException::withMessages([$key => 'Ne partagez aucun mot de passe ni coffre personnel pour ce service.']);
                        }
                    }
                    $emailInvitation = $this->invitationChannel($current) === 'provider_email';
                    if ($emailInvitation) {
                        abort_unless($target->user && $this->access->canUseService($target->user, $current), 409, 'Attendez la confirmation du paiement avant d’envoyer l’invitation.');
                        validator($data, ['invitation_sent' => ['required', 'accepted'], 'invitation_url' => ['prohibited']])->validate();
                    }
                    $target->forceFill([
                        'service_invitation_url' => $emailInvitation ? null : $this->urls->validate($current, $data['invitation_url'] ?? null),
                        'service_invitation_channel' => $emailInvitation ? 'provider_email' : 'link',
                        'service_invitation_email' => $emailInvitation ? $target->user->email : null,
                        'service_invitation_provided_at' => now(),
                    ])->save();
                });
            });
        });
    }

    public function confirmRevocation(User $owner, Group $group, GroupMember $member): void
    {
        $this->lock->run('access-delivery:'.$group->id, function (Closure $assertOwned) use ($owner, $group, $member): void {
            $current = Group::withTrashed()->findOrFail($group->id);
            $this->authorizeOwner($owner->fresh(), $current);
            abort_unless($member->group_id === $current->id && $member->role === 'member', 404);
            $target = $member->fresh();
            if ($target->service_access_revoked_at) {
                return;
            }
            abort_unless($this->revocationRequired($current, $target), 409, 'L’adhésion est encore en cours.');
            $assertOwned();
            // Owner attestation, not a claim of an automated provider operation.
            $target->forceFill(['service_invitation_url' => null, 'service_access_revoked_at' => now()])->save();
        });
    }
}
