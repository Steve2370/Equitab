<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Group\StoreGroupRequest;
use App\Http\Requests\Group\UpdateGroupRequest;
use App\Models\Group;
use App\Models\Subscription;
use App\Repositories\Contracts\GroupRepositoryInterface;
use App\Services\Group\GroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;
use Inertia\Inertia;

class GroupController extends Controller
{
    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly GroupService $groupService,
    ) {}

    public function index(): JsonResponse
    {
        $groups = $this->groupRepository->findAvailable();

        return response()->json($groups);
    }

    public function create(Request $request): \Inertia\Response
    {
        $user = $request->user();

        if ($user->identity_status !== 'verified' || $user->stripe_connect_status !== 'active') {
            return Inertia::render('Dashboard/Groups/Create', [
                'subscriptions' => [],
                'verificationError' => true,
                'identityVerified' => $user->identity_status === 'verified',
                'connectActive' => $user->stripe_connect_status === 'active',
            ]);
        }

        $subscriptions = \App\Models\Subscription::where('is_active', true)
            ->with('category')
            ->get()
            ->map(fn($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'max_members' => $s->max_members,
                'monthly_price' => $s->monthly_price,
                'category' => $s->category->name,
            ]);

        return Inertia::render('Dashboard/Groups/Create', [
            'subscriptions' => $subscriptions,
            'verificationError' => false,
        ]);
    }

    public function store(StoreGroupRequest $request): RedirectResponse
    {
        $group = $this->groupService->create($request->user(), $request->validated());

        return redirect()->route('dashboard.subscriptions')
            ->with('success', 'Votre groupe a été créé avec succès !');
    }

    public function show(Group $group): JsonResponse
    {
        return response()->json(
            $group->load(['subscription', 'owner', 'activeMembers'])
        );
    }

    public function update(UpdateGroupRequest $request, Group $group): JsonResponse
    {
        $updated = $this->groupRepository->update($group, $request->validated());

        return response()->json([
            'message' => 'Groupe mis à jour.',
            'group' => $updated,
        ]);
    }

    public function destroy(Request $request, Group $group): JsonResponse
    {
        $this->authorize('delete', $group);

        $this->groupRepository->delete($group);

        return response()->json([
            'message' => 'Groupe fermé.',
        ]);
    }

    public function join(Request $request, Group $group): JsonResponse
    {
        $this->groupService->join($request->user(), $group);

        return response()->json([
            'message' => 'Vous avez rejoint le groupe.',
        ]);
    }

    public function credentials(Request $request, Group $group): JsonResponse
    {
        $user = $request->user();

        $isMemberActive = $group->members()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        $isOwner = $group->owner_id === $user->id;

        if (! $isMemberActive && ! $isOwner) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        return response()->json([
            'email' => $group->credential_email,
            'password' => $group->credential_password,
            'notes' => $group->credential_notes,
        ]);
    }

    public function leave(Request $request, Group $group): JsonResponse
    {
        $this->groupService->leave($request->user(), $group);

        return response()->json([
            'message' => 'Vous avez quitté le groupe.',
        ]);
    }

    public function byService(string $slug): Response
    {
        $subscription = Subscription::where('slug', $slug)->firstOrFail();

        $groups = Group::with(['owner', 'subscription'])
            ->where('subscription_id', $subscription->id)
            ->where('status', 'open')
            ->where('visibility', 'public')
            ->whereColumn('current_members', '<', 'max_members')
            ->get()
            ->map(fn ($group) => [
                'id' => $group->id,
                'subscriptionName' => $group->subscription->name,
                'ownerName' => $group->owner->name,
                'ownerIdentityStatus' => $group->owner->identity_status,
                'ownerActiveGroupsCount' => $group->owner->ownedGroups()->where('status', '!=', 'closed')->count(),
                'tier' => $group->subscription->tier,
                'pricePerMember' => $group->price_per_member,
                'spotsAvailable' => $group->max_members - $group->current_members,
                'maxMembers' => $group->max_members,
                'createdAt' => $group->created_at->format('d M Y'),
            ]);

        return Inertia::render('ServiceGroups', [
            'subscription' => [
                'name' => $subscription->name,
                'slug' => $subscription->slug,
            ],
            'groups' => $groups,
            'canLogin' => true,
            'canRegister' => true,
            'isAuthenticated' => Auth::check(),
        ]);
    }
}
