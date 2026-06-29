<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Models\Group;
use App\Models\User;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Mail\NewMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $conversations = $user->groupMembers()
            ->with(['group.subscription', 'group.owner'])
            ->where('status', 'active')
            ->get()
            ->map(function ($member) use ($user) {
                $group = $member->group;
                $other = $group->owner_id === $user->id
                    ? null
                    : $group->owner;

                $lastMessage = Message::where('group_id', $group->id)
                    ->where(function ($q) use ($user) {
                        $q->where('sender_id', $user->id)
                          ->orWhere('receiver_id', $user->id);
                    })
                    ->latest()
                    ->first();

                $unread = Message::where('group_id', $group->id)
                    ->where('receiver_id', $user->id)
                    ->whereNull('read_at')
                    ->count();

                return [
                    'groupId' => $group->id,
                    'subscriptionName' => $group->subscription->name,
                    'otherName' => $other?->name ?? 'Vous (propriétaire)',
                    'otherId' => $other?->id,
                    'lastMessage' => $lastMessage?->body,
                    'lastMessageAt' => $lastMessage?->created_at->diffForHumans(),
                    'unreadCount' => $unread,
                ];
            });

        return Inertia::render('Dashboard/Chat', [
            'conversations' => $conversations,
        ]);
    }

    public function show(Request $request, Group $group): JsonResponse
    {
        $user = $request->user();

        $messages = Message::where('group_id', $group->id)
            ->where(function ($q) use ($user) {
                $q->where('sender_id', $user->id)
                  ->orWhere('receiver_id', $user->id);
            })
            ->with('sender')
            ->orderBy('created_at')
            ->get()
            ->map(fn($m) => [
                'id' => $m->id,
                'body' => $m->body,
                'sender_id' => $m->sender_id,
                'sender_name' => $m->sender->name,
                'created_at' => $m->created_at->format('H:i'),
                'is_mine' => $m->sender_id === $user->id,
            ]);

        Message::where('group_id', $group->id)
            ->where('receiver_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json($messages);
    }

    public function send(Request $request, Group $group): JsonResponse
    {
        $request->validate(['body' => ['required', 'string', 'max:1000']]);

        $user = $request->user();

        $receiverId = $group->owner_id === $user->id
            ? $request->input('receiver_id')
            : $group->owner_id;

        $message = Message::create([
            'group_id' => $group->id,
            'sender_id' => $user->id,
            'receiver_id' => $receiverId,
            'body' => $request->body,
        ]);

        $message->load('sender');

        broadcast(new MessageSent($message));

        $receiver = User::find($receiverId);
        if ($receiver && ($receiver->allow_direct_contact ?? true)) {
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
            'created_at' => $message->created_at->format('H:i'),
            'is_mine' => true,
        ], 201);
    }
}
