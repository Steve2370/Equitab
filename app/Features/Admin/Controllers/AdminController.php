<?php

namespace App\Features\Admin\Controllers;

use App\Features\Payment\Services\BillingReconciliationService;
use App\Features\Payment\Services\SubscriptionCancellationService;
use App\Http\Controllers\Controller;
use App\Mail\AdminMessage;
use App\Models\Dispute;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Index', [
            'stats' => [
                'totalUsers' => User::count(),
                'totalGroups' => Group::count(),
                'activeGroups' => Group::where('status', 'open')->count(),
                'totalPayments' => Payment::where('status', 'completed')->count(),
                'totalRevenue' => Payment::where('status', 'completed')->sum('amount'),
                'equitabEarnings' => Payment::where('status', 'completed')->sum('platform_fee_amount'),
                'openDisputes' => Dispute::where('status', 'open')->count(),
                'verifiedUsers' => User::where('identity_status', 'verified')->count(),
            ],
        ]);
    }

    public function users(): Response
    {
        $users = User::withCount(['ownedGroups', 'groupMembers'])
            ->latest()
            ->paginate(20)
            ->through(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'identityStatus' => $u->identity_status,
                'connectStatus' => $u->stripe_connect_status,
                'trustScore' => $u->trust_score,
                'groupsOwned' => $u->owned_groups_count,
                'groupsJoined' => $u->group_members_count,
                'createdAt' => $u->created_at->format('d M Y'),
                'status' => $u->status,
                'isSuspended' => $u->isSuspended(),
                'suspendedUntil' => $u->suspended_until?->format('d M Y H:i'),
                'suspensionReason' => $u->suspension_reason,
            ]);

        return Inertia::render('Admin/Users', ['users' => $users]);
    }

    public function suspendUser(Request $request, User $user): RedirectResponse
    {
        if ($user->id === Auth::id()) {
            return back()->with('error', 'Vous ne pouvez pas suspendre votre propre compte.');
        }

        $request->validate([
            // Nombre de jours ; null/absent = suspension indéfinie, jusqu'à
            // ce qu'un admin la lève manuellement.
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $user->update([
            'status' => 'suspended',
            'suspended_until' => $request->duration_days
                ? now()->addDays((int) $request->duration_days)
                : null,
            'suspension_reason' => $request->reason,
        ]);

        return back()->with('success', "Compte de {$user->name} suspendu.");
    }

    public function unsuspendUser(User $user): RedirectResponse
    {
        $user->update([
            'status' => 'active',
            'suspended_until' => null,
            'suspension_reason' => null,
        ]);

        return back()->with('success', "Compte de {$user->name} réactivé.");
    }

    public function groups(): Response
    {
        $groups = Group::with(['owner', 'subscription', 'members.user'])
            ->withCount('members')
            ->latest()
            ->paginate(20)
            ->through(fn ($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'ownerName' => $g->owner->name ?? 'Utilisateur supprimé',
                'ownerEmail' => $g->owner->email ?? '—',
                'subscriptionName' => $g->subscription->name ?? '—',
                'status' => $g->status,
                'visibility' => $g->visibility,
                'membersCount' => $g->members_count,
                'maxMembers' => $g->max_members,
                'totalPrice' => $g->total_price,
                'createdAt' => $g->created_at->format('d M Y'),
                // La composition du groupe (qui est dans quel groupe) —
                // manquait ici alors que la page Admin/Groups.vue l'attend
                // déjà pour son panneau dépliable.
                'members' => $g->members->map(fn ($m) => [
                    'id' => $m->user_id,
                    'name' => $m->user->name ?? 'Utilisateur supprimé',
                    'email' => $m->user->email ?? '—',
                    'avatar' => $m->user->avatar ?? null,
                    'role' => $m->role,
                    'status' => $m->status,
                    'joinedAt' => $m->joined_at?->format('d M Y'),
                ])->values(),
            ]);

        return Inertia::render('Admin/Groups', ['groups' => $groups]);
    }

    public function payments(): Response
    {
        $payments = Payment::with(['group.subscription', 'user'])
            ->where('status', 'completed')
            ->latest('paid_at')
            ->paginate(20)
            ->through(fn ($p) => [
                'id' => $p->id,
                'userName' => $p->user->name ?? 'Utilisateur supprimé',
                'userEmail' => $p->user->email ?? '—',
                'groupName' => $p->group->name ?? '—',
                'subscriptionName' => $p->group->subscription->name ?? '—',
                'amount' => $p->amount,
                'equitabFee' => $p->platform_fee_amount,
                'currency' => $p->currency,
                'paidAt' => $p->paid_at?->format('d M Y H:i'),
            ]);

        $totalEarnings = Payment::where('status', 'completed')->sum('platform_fee_amount');

        return Inertia::render('Admin/Payments', [
            'payments' => $payments,
            'totalEarnings' => $totalEarnings,
        ]);
    }

    public function disputes(): Response
    {
        // Restore archived group context only for this authorized history view;
        // deleted account identity and global model relations stay hidden.
        $disputes = Dispute::with(['user', 'payment', 'group' => fn ($query) => $query->withTrashed()->with('subscription')])
            ->latest()
            ->paginate(20)
            ->through(fn ($d) => [
                'id' => $d->id,
                'userName' => $d->user?->name ?? 'Utilisateur supprimé',
                'userEmail' => $d->user?->email ?? '—',
                'groupName' => $d->group?->name ?? 'Groupe supprimé',
                'amount' => $d->payment?->amount ?? 0,
                'subscriptionName' => $d->group?->subscription?->name ?? 'Service indisponible',
                'reason' => $d->reason,
                'description' => $d->description,
                'status' => $d->status,
                'createdAt' => $d->created_at->format('d M Y'),
            ]);

        return Inertia::render('Admin/Disputes', ['disputes' => $disputes]);
    }

    public function resolveDispute(Request $request, Dispute $dispute, BillingReconciliationService $billing): RedirectResponse
    {
        $request->validate([
            'status' => ['required', 'in:resolved_refund,resolved_rejected'],
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);
        if (in_array($dispute->status, ['resolved_refund', 'resolved_rejected'], true)) {
            return back()->with('error', 'Ce litige est déjà résolu.');
        }
        if ($request->status === 'resolved_refund') {
            $payment = $dispute->payment;
            if (! $payment || ! $payment->stripe_payment_intent_id) {
                return back()->with('error', 'Ce paiement doit être rapproché avec Stripe avant remboursement.');
            }
            $dispute->update(['admin_notes' => $request->admin_notes]);
            try {
                $payment = $billing->refund($payment, 'dispute_resolved');
            } catch (\Throwable) {
                Log::warning('Remboursement du litige à reprendre.', ['dispute_id' => $dispute->id]);

                return back()->with('error', 'Le remboursement ne peut pas être confirmé ; la demande reste à suivre.');
            }
            if ($payment->status !== 'refunded') {
                return back()->with('success', 'Demande de remboursement enregistrée, confirmation en attente.');
            }
        }
        if ($request->status === 'resolved_rejected'
            && DB::table('payment_refund_attempts')->where('payment_id', $dispute->payment_id)->exists()) {
            return back()->with('error', 'Un remboursement a déjà été demandé ; vérifier son état avant de rejeter le litige.');
        }
        $dispute->update([
            'status' => $request->status,
            'admin_notes' => $request->admin_notes,
            'resolved_at' => $dispute->resolved_at ?? now(),
        ]);

        return back()->with('success', 'Dispute résolue avec succès.');
    }

    public function messages(): Response
    {
        $users = User::select('id', 'name', 'email')->orderBy('name')->get();

        return Inertia::render('Admin/Messages', ['users' => $users]);
    }

    public function sendMessage(Request $request): RedirectResponse
    {
        $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'recipients' => ['required', 'in:all,specific'],
            'user_ids' => ['required_if:recipients,specific', 'array'],
            'user_ids.*' => ['exists:users,id'],
        ]);

        if ($request->recipients === 'all') {
            $users = User::all();
        } else {
            $users = User::whereIn('id', $request->user_ids)->get();
        }

        foreach ($users as $user) {
            Mail::to($user->email)->send(
                new AdminMessage($user, $request->subject, $request->body)
            );
        }

        return back()->with('success', "Message envoyé à {$users->count()} utilisateur(s).");
    }

    public function deleteUser(User $user, SubscriptionCancellationService $cancellations): RedirectResponse
    {
        if ($user->id === Auth::id()) {
            return back()->with('error', 'Vous ne pouvez pas supprimer votre propre compte.');
        }
        // Persist all intentions before remote calls; soft delete retains replay targets.
        $members = DB::transaction(function () use ($user) {
            $ownedIds = Group::withTrashed()->where('owner_id', $user->id)->pluck('id');
            Group::withTrashed()->whereIn('id', $ownedIds)->update(['status' => 'closed']);
            $members = GroupMember::where(fn ($query) => $query->where('user_id', $user->id)
                ->orWhereIn('group_id', $ownedIds))->lockForUpdate()->get();
            foreach ($members as $member) {
                $member->update(['cancellation_requested_at' => $member->cancellation_requested_at ?? now()]);
            }
            $user->delete();

            return $members;
        });
        $pending = 0;
        foreach ($members as $member) {
            try {
                if (! $cancellations->request($member)) {
                    $pending++;
                }
            } catch (\Throwable) {
                $pending++;
                Log::warning('Annulation après suppression de compte à reprendre.', ['member_id' => $member->id]);
            }
        }
        if ($pending > 0) {
            return back()->with('error', 'Compte supprimé ; des annulations restent en attente et seront réessayées.');
        }

        return back()->with('success', 'Utilisateur supprimé et annulations confirmées.');
    }
}
