<?php

namespace App\Features\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\AdminMessage;
use App\Mail\AutoRefundProcessed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
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
            ->through(fn($u) => [
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
            ->through(fn($g) => [
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
                'members' => $g->members->map(fn($m) => [
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
            ->through(fn($p) => [
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
        $disputes = Dispute::with(['user', 'group.subscription'])
            ->latest()
            ->paginate(20)
            ->through(fn($d) => [
                'id' => $d->id,
                'userName' => $d->user->name,
                'userEmail' => $d->user->email,
                'groupName' => $d->group->name,
                'amount' => $d->payment?->amount ?? 0,
                'subscriptionName' => $d->group->subscription->name,
                'reason' => $d->reason,
                'description' => $d->description,
                'status' => $d->status,
                'createdAt' => $d->created_at->format('d M Y'),
            ]);

        return Inertia::render('Admin/Disputes', ['disputes' => $disputes]);
    }

    public function resolveDispute(Request $request, Dispute $dispute): RedirectResponse
    {
        $request->validate([
            'status' => ['required', 'in:resolved_refund,resolved_rejected'],
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $dispute->update([
            'status' => $request->status,
            'admin_notes' => $request->admin_notes,
            'resolved_at' => now(),
        ]);

        if ($request->status === 'resolved_refund' && $dispute->payment->stripe_payment_intent_id) {
            app(PaymentGatewayInterface::class)
                ->refundPayment($dispute->payment->stripe_payment_intent_id);

            $dispute->payment->update([
                'status' => 'refunded',
                'refunded_at' => now(),
                'refund_reason' => 'dispute_resolved',
            ]);

            Mail::to($dispute->user->email)
                ->send(new AutoRefundProcessed(
                    $dispute->payment->load('group.subscription'),
                    $dispute->user
                ));
        }

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

    public function deleteUser(\App\Models\User $user): \Illuminate\Http\RedirectResponse
    {
        if ($user->id === Auth::id()) {
            return back()->with('error', 'Vous ne pouvez pas supprimer votre propre compte.');
        }

        $user->groupMembers()->where('status', 'active')->each(function ($member) {
            if ($member->stripe_subscription_id) {
                try {
                    app(\App\Features\Payment\Contracts\PaymentGatewayInterface::class)
                        ->cancelSubscription($member->stripe_subscription_id);
                } catch (\Exception $e) {
                    Log::error('Cancel sub error: ' . $e->getMessage());
                }
            }
            $member->update(['status' => 'left']);
        });

        $user->ownedGroups()->where('status', 'open')->each(function ($group) {
            $group->update(['status' => 'closed']);
        });

        $user->delete();
        return back()->with('success', 'Utilisateur supprimé.');
    }
}
