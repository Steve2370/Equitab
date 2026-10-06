<?php

namespace App\Features\Chat\Controllers;

use App\Events\MessageSent;
use App\Features\Group\Services\GroupAccess;
use App\Http\Controllers\Controller;
use App\Mail\NewMessage;
use App\Models\Group;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function __construct(private readonly GroupAccess $access) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $memberships = $user->groupMembers()
            ->with(['group.subscription', 'group.owner'])
            ->where('status', 'active')
            ->whereHas('group')
            ->get();

        $conversations = collect();

        foreach ($memberships as $member) {
            $group = $member->group;
            if (! $this->access->canUseService($user, $group)) {
                continue;
            }
            $isOwner = $group->owner_id === $user->id;

            if ($isOwner) {
                $otherMembers = $group->activeMembers()
                    ->with('user')
                    ->where('user_id', '!=', $user->id)
                    ->get();

                foreach ($otherMembers as $otherMember) {
                    $other = $otherMember->user;
                    if ($other && $this->access->canChat($user, $group, $other)) {
                        $conversations->push($this->buildConversation($group, $user, $other));
                    }
                }
            } elseif ($group->owner && $this->access->canChat($user, $group, $group->owner)) {
                $conversations->push($this->buildConversation($group, $user, $group->owner));
            }
        }

        return Inertia::render('Dashboard/Chat', [
            'conversations' => $conversations->values(),
        ]);
    }

    private function buildConversation(Group $group, User $user, User $other): array
    {
        $lastMessage = Message::where('group_id', $group->id)
            ->where(function ($q) use ($user, $other) {
                $q->where(fn ($q2) => $q2->where('sender_id', $user->id)->where('receiver_id', $other->id))
                    ->orWhere(fn ($q2) => $q2->where('sender_id', $other->id)->where('receiver_id', $user->id));
            })
            ->latest()
            ->first();

        $unread = Message::where('group_id', $group->id)
            ->where('sender_id', $other->id)
            ->where('receiver_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return [
            'groupId' => $group->id,
            'subscriptionName' => $group->subscription->name,
            'otherName' => $other->name,
            'otherId' => $other->id,
            'otherAvatar' => $other->avatar,
            'lastMessage' => $lastMessage?->body,
            'lastMessageAt' => $lastMessage?->created_at->diffForHumans(),
            'unreadCount' => $unread,
        ];
    }

    /**
     * Liste des membres actifs qu'un propriétaire peut contacter — sert au
     * sélecteur "nouvelle conversation" côté frontend.
     */
    public function members(Request $request, Group $group): JsonResponse
    {
        $user = $request->user();

        if ($group->owner_id !== $user->id || ! $this->access->canUseService($user, $group)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        $members = $group->activeMembers()
            ->with('user')
            ->where('user_id', '!=', $user->id)
            ->get()
            ->filter(fn ($m) => $m->user && $this->access->canChat($user, $group, $m->user))
            ->values()
            ->map(fn ($m) => [
                'id' => $m->user->id,
                'name' => $m->user->name,
                'avatar' => $m->user->avatar,
            ]);

        return response()->json($members);
    }

    public function show(Request $request, Group $group): JsonResponse
    {
        $user = $request->user();
        $isOwner = $group->owner_id === $user->id;
        if (! $this->access->canUseService($user, $group)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        $otherId = (int) $request->query('other_id');

        // Le membre ne parle qu'au propriétaire — on ignore other_id venant
        // du frontend et on force la bonne valeur pour éviter toute usurpation.
        if (! $isOwner) {
            $otherId = $group->owner_id;
        } elseif (! $otherId) {
            return response()->json(['message' => 'Destinataire requis.'], 422);
        }

        $other = User::find($otherId);
        if (! $other || ! $this->access->canChat($user, $group, $other)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        $messages = Message::where('group_id', $group->id)
            ->where(function ($q) use ($user, $otherId) {
                $q->where(fn ($q2) => $q2->where('sender_id', $user->id)->where('receiver_id', $otherId))
                    ->orWhere(fn ($q2) => $q2->where('sender_id', $otherId)->where('receiver_id', $user->id));
            })
            ->with('sender')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'body' => $m->body,
                'sender_id' => $m->sender_id,
                'sender_name' => $m->sender->name,
                'sender_avatar' => $m->sender->avatar,
                'created_at' => $m->created_at->format('H:i'),
                'is_mine' => $m->sender_id === $user->id,
            ]);

        Message::where('group_id', $group->id)
            ->where('sender_id', $otherId)
            ->where('receiver_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json($messages);
    }

    public function send(Request $request, Group $group): JsonResponse
    {
        $request->validate(['body' => ['required', 'string', 'max:1000']]);

        $user = $request->user();
        $isOwner = $group->owner_id === $user->id;
        if (! $this->access->canUseService($user, $group)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if ($isOwner) {
            $receiverId = (int) $request->input('receiver_id');
        } else {
            $receiverId = $group->owner_id;
        }

        $receiver = User::find($receiverId);
        if (! $receiver || ! $this->access->canChat($user, $group, $receiver)) {
            return response()->json(['message' => 'Destinataire invalide.'], 422);
        }

        $message = Message::create([
            'group_id' => $group->id,
            'sender_id' => $user->id,
            'receiver_id' => $receiverId,
            'body' => $request->body,
        ]);

        $message->load('sender');

        broadcast(new MessageSent($message));

        if ($receiver->allow_direct_contact ?? true) {
            Mail::to($receiver->email)
                ->send(new NewMessage(
                    recipient: $receiver,
                    sender: $user,
                    groupName: $group->name,
                    messagePreview: Str::limit($request->body, 100),
                    groupId: $group->id,
                ));
        }

        return response()->json([
            'id' => $message->id,
            'body' => $message->body,
            'sender_id' => $message->sender_id,
            'sender_name' => $message->sender->name,
            'sender_avatar' => $message->sender->avatar,
            'created_at' => $message->created_at->format('H:i'),
            'is_mine' => true,
        ], 201);
    }
}
