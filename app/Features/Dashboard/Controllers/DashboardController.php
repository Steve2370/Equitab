<?php

namespace App\Features\Dashboard\Controllers;

use App\Features\Payment\Services\OwnerOnboardingException;
use App\Features\Payment\Services\OwnerOnboardingService;
use App\Http\Controllers\Controller;
use App\Mail\IdentityVerified;
use App\Models\GroupDraft;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $activeGroups = $user->groupMembers()
            ->with('group.subscription')
            ->where('status', 'active')
            ->whereHas('group', fn ($q) => $q->whereNotIn('status', ['closed']))
            ->get();

        $totalSavings = $activeGroups->sum(function ($member) {
            $fullPrice = $member->group->subscription->monthly_price;
            $sharedPrice = $member->share_amount;

            return ($fullPrice - $sharedPrice) / 100;
        });

        $monthlySpend = $activeGroups->sum(fn ($m) => $m->share_amount / 100);

        $upcomingPayments = $user->payments()
            // History remains visible after a group is archived. Do not alter
            // the global relation used by authorization and payment workflows.
            ->with(['group' => fn ($query) => $query->withTrashed()->with('subscription')])
            ->where('status', 'pending')
            ->orderBy('due_date')
            ->limit(5)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'groupName' => $p->group
                    ? ($p->group->subscription?->name ?? 'Service indisponible').' — '.$p->group->name
                    : 'Groupe indisponible',
                'amount' => $p->amount,
                'status' => $p->status,
                'paidAt' => $p->paid_at?->format('d M Y'),
                'dueDate' => $p->due_date->format('d M Y'),
            ]);

        return Inertia::render('Dashboard/Index', [
            'userName' => $user->name,
            'totalSavings' => $totalSavings,
            'monthlySpend' => $monthlySpend,
            'upcomingPayments' => $upcomingPayments,
            'activeSubscriptionsCount' => $activeGroups->count(),
        ]);
    }

    public function subscriptions(Request $request): Response
    {
        $user = $request->user();

        $joined = $user->groupMembers()
            ->with(['group.subscription', 'group.owner'])
            ->where('role', 'member')
            ->whereIn('status', ['active', 'pending_payment'])
            ->whereHas('group', fn ($q) => $q->whereNotIn('status', ['closed']))
            ->get()
            ->map(fn ($m) => [
                'id' => $m->group->id,
                'subscriptionName' => $m->group->subscription->name,
                'ownerName' => $m->group->owner?->display_name ?? 'Utilisateur supprimé',
                'pricePerMember' => $m->share_amount,
                'joinedAt' => $m->joined_at?->format('d/m/Y'),
                'status' => $m->status,
                'spotsLeft' => $m->group->max_members - $m->group->current_members,
                'subscriptionSlug' => $m->group->subscription->slug,
            ]);

        $owned = $user->ownedGroups()
            ->with('subscription')
            ->whereNotIn('status', ['closed'])
            ->get()
            ->map(fn ($g) => [
                'id' => $g->id,
                'subscriptionName' => $g->subscription->name,
                'membersCount' => $g->current_members,
                'maxMembers' => $g->max_members,
                'pricePerMember' => $g->calculateCurrentPricePerMember(),
                'totalPrice' => $g->total_price,
                'status' => $g->status,
                'inviteLink' => $g->invite_token ? config('app.url').'/invite/'.$g->invite_token : null,
                'renewalDate' => $g->renewal_date?->format('d M Y'),
            ]);

        return Inertia::render('Dashboard/Subscriptions', [
            'initialTab' => $request->query('tab') === 'owned' ? 'owned' : 'joined',
            'joinedSubscriptions' => $joined,
            'ownedSubscriptions' => $owned,
            'drafts' => GroupDraft::where('owner_id', $user->id)
                ->where('status', '!=', 'published')->latest('updated_at')->get()
                ->map(fn ($draft) => [
                    'id' => $draft->id,
                    'name' => $draft->data['name'] ?? 'Mon prochain partage',
                    'status' => $draft->status,
                    'updatedAt' => $draft->updated_at->format('d/m/Y H:i'),
                    'url' => route('group-drafts.edit', $draft, absolute: false),
                ]),
        ]);
    }

    public function payments(Request $request): Response
    {
        $payments = $request->user()->payments()
            ->with(['group' => fn ($query) => $query->withTrashed()->with('subscription')])
            ->latest('paid_at')
            ->paginate(20)
            ->through(fn ($p) => [
                'id' => $p->id,
                'groupName' => $p->group?->subscription?->name ?? 'Service indisponible',
                'amount' => $p->amount,
                'status' => $p->status,
                'paidAt' => $p->paid_at?->format('d M Y'),
                'dueDate' => $p->due_date->format('d M Y'),
            ]);

        return Inertia::render('Dashboard/Payments', [
            'payments' => $payments,
        ]);
    }

    public function chat(Request $request): Response
    {
        $groups = $request->user()->groupMembers()
            ->with(['group.subscription', 'group.owner'])
            ->where('status', 'active')
            ->whereHas('group')
            ->get()
            ->map(fn ($m) => [
                'groupId' => $m->group->id,
                'subscriptionName' => $m->group->subscription->name,
                'ownerName' => $m->group->owner?->display_name ?? 'Utilisateur supprimé',
                'slug' => $m->group->subscription->slug,
            ]);

        return Inertia::render('Dashboard/Chat', [
            'groups' => $groups,
        ]);
    }

    public function profile(Request $request, OwnerOnboardingService $onboarding): Response
    {
        $user = $request->user();

        if ($user->stripe_identity_session_id || $user->stripe_connect_account_id) {
            try {
                $identityWasVerified = $user->identity_status === 'verified';
                $onboarding->refresh($user);
                if (! $identityWasVerified && $user->identity_status === 'verified') {
                    Mail::to($user->email)->send(new IdentityVerified($user));
                }
            } catch (OwnerOnboardingException) {
                $request->session()->flash('error', 'La vérification des versements est temporairement indisponible. Réessayez avant de publier.');
            }
        }

        $resumeDraft = GroupDraft::where('owner_id', $user->id)
            ->whereKey($request->session()->get('owner_draft_id'))
            ->where('status', '!=', 'published')->first();

        return Inertia::render('Dashboard/Profile', [
            'resumeDraftUrl' => $resumeDraft ? route('group-drafts.edit', $resumeDraft, absolute: false) : null,
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'address' => $user->address,
                'city' => $user->city,
                'province' => $user->province,
                'postal_code' => $user->postal_code,
                'identity_status' => $user->identity_status,
                'stripe_connect_status' => $user->stripe_connect_status,
                'trust_score' => $user->trust_score,
                'completed_payments' => $user->completed_payments_count,
            ],
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:2'],
            'postal_code' => ['nullable', 'string', 'max:10'],
        ]);

        $request->user()->update($request->only([
            'name', 'phone', 'address', 'city', 'province', 'postal_code',
        ]));

        return back()->with('success', 'Profil mis à jour avec succès.');
    }

    public function preferences(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard/Preferences', [
            'user' => [
                'name' => $user->name,
                'username' => $user->username,
                'avatar' => $user->avatar,
                'email' => $user->email,
                'timezone' => $user->timezone ?? 'America/Toronto',
                'notif_member_joined' => $user->notif_member_joined ?? true,
                'notif_payment_received' => $user->notif_payment_received ?? true,
                'notif_renewal_reminder' => $user->notif_renewal_reminder ?? true,
                'notif_payment_failed' => $user->notif_payment_failed ?? true,
                'show_real_name' => $user->show_real_name ?? true,
                'allow_direct_contact' => $user->allow_direct_contact ?? true,
            ],
        ]);
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $request->validate([
            'username' => ['nullable', 'string', 'max:30', 'unique:users,username,'.$request->user()->id],
            'timezone' => ['required', 'string', 'timezone'],
            'notif_member_joined' => ['boolean'],
            'notif_payment_received' => ['boolean'],
            'notif_renewal_reminder' => ['boolean'],
            'notif_payment_failed' => ['boolean'],
            'show_real_name' => ['boolean'],
            'allow_direct_contact' => ['boolean'],
        ]);

        $request->user()->update($request->only([
            'username', 'timezone',
            'notif_member_joined', 'notif_payment_received',
            'notif_renewal_reminder', 'notif_payment_failed',
            'show_real_name', 'allow_direct_contact',
        ]));

        return back()->with('success', 'Préférences mises à jour.');
    }

    public function updateAvatar(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $user = $request->user();

        if ($user->avatar) {
            $oldKey = str_replace(config('services.cloudflare.r2_url').'/', '', $user->avatar);
            Storage::disk('r2')->delete($oldKey);
        }

        $file = $request->file('avatar');
        $filename = 'avatars/'.$user->id.'_'.time().'.'.$file->getClientOriginalExtension();

        Storage::disk('r2')->put($filename, file_get_contents($file), 'public');

        $url = config('services.cloudflare.r2_url').'/'.$filename;
        $user->update(['avatar' => $url]);

        return back()->with('success', 'Avatar mis à jour.');
    }

    public function deleteAccount(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        Auth::logout();
        $user->delete();

        return redirect('/')->with('success', 'Votre compte a été supprimé.');
    }
}
