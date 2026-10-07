<?php

namespace App\Features\Group\Controllers;

use App\Features\Group\Repositories\Contracts\GroupRepositoryInterface;
use App\Features\Group\Requests\StoreGroupRequest;
use App\Features\Group\Requests\UpdateGroupRequest;
use App\Features\Group\Services\GroupAccess;
use App\Features\Group\Services\GroupService;
use App\Features\Group\Services\OwnerGroupPage;
use App\Features\Payment\Services\BillingUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Resources\GroupResource;
use App\Models\Group;
use App\Models\Subscription;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GroupController extends Controller
{
    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly GroupService $groupService,
        private readonly GroupAccess $access,
    ) {}

    public function index(): JsonResponse
    {
        $groups = $this->groupRepository->findAvailable();

        return GroupResource::collection($groups)->response();
    }

    public function create(Request $request, OwnerGroupPage $page): Response
    {
        return Inertia::render('Dashboard/Groups/Create', $page->props($request->user()));
    }

    public function store(StoreGroupRequest $request): RedirectResponse
    {
        try {
            $group = $this->groupService->create($request->user(), $request->validated());
        } catch (Exception $e) {
            return back()->with('error', $e->getMessage())->withInput($request->safe()->except([
                'credential_email', 'credential_password', 'credential_notes',
            ]));
        }

        return redirect()->route('dashboard.subscriptions')
            ->with('success', 'Votre groupe a été créé avec succès !');
    }

    public function show(Request $request, Group $group): JsonResponse
    {
        abort_unless($this->access->canView($request->user('sanctum'), $group), 404);

        return (new GroupResource($group->load(['subscription', 'owner'])))
            ->response();
    }

    public function update(UpdateGroupRequest $request, Group $group): JsonResponse
    {
        try {
            $updated = $this->groupService->update($request->user(), $group, $request->validated());
        } catch (BillingUnavailable) {
            return response()->json(['message' => 'Ce groupe est en cours de traitement. Réessayez dans un instant.'], 503);
        }

        return response()->json([
            'message' => 'Groupe mis à jour.',
            'group' => $updated,
        ]);
    }

    public function destroy(Request $request, Group $group): JsonResponse
    {
        $this->authorize('delete', $group);

        $complete = $this->groupService->close($request->user(), $group, delete: true);

        return response()->json([
            'message' => $complete ? 'Groupe fermé.' : 'Groupe fermé. Les annulations de paiement sont en cours.',
        ]);
    }

    public function join(Request $request, Group $group): JsonResponse
    {
        $request->validate(['invite_token' => ['nullable', 'string', 'max:255']]);
        try {
            $this->groupService->join(
                $request->user(),
                $group,
                $request->string('invite_token')->value() ?: null,
            );
        } catch (BillingUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json([
            'message' => 'Vous avez rejoint le groupe.',
        ]);
    }

    public function credentials(Request $request, Group $group): JsonResponse
    {
        if (! $this->access->canUseService($request->user(), $group)) {
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
        $subscription = Subscription::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $groups = Group::with(['owner', 'subscription'])
            ->where('subscription_id', $subscription->id)
            ->where('status', 'open')
            ->where('visibility', 'public')
            ->whereHas('owner')
            ->where('current_members', '<', DB::raw('max_members'))
            ->with(['owner', 'subscription'])
            ->get()
            ->map(fn ($group) => [
                'id' => $group->id,
                'subscriptionName' => $group->subscription->name,
                // Description libre, optionnelle, que le propriétaire peut
                // renseigner à la création pour préciser l'offre partagée
                // (ex: "Crunchyroll Megafan", "Netflix Famille 4K") — visible
                // par les utilisateurs qui envisagent de rejoindre.
                'description' => $group->description,
                'ownerName' => $group->owner->display_name,
                'ownerIdentityStatus' => $group->owner->identity_status,
                'ownerActiveGroupsCount' => $group->owner->ownedGroups()->where('status', '!=', 'closed')->count(),
                'ownerTrustScore' => $group->owner->calculateTrustScore(),
                'tier' => $group->subscription->tier ?? 'standard',
                'pricePerMember' => $group->calculatePricePerMemberIfJoined(),
                'totalPrice' => $group->total_price,
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

    public function close(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('update', $group);

        $complete = $this->groupService->close($request->user(), $group);

        return back()->with('success', $complete
            ? 'Le groupe a été fermé.'
            : 'Le groupe a été fermé. Les annulations de paiement sont en cours.');
    }
}
