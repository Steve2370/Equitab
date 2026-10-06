<?php

use App\Features\Group\Services\GroupAccess;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Both peers must be entitled to this owner/member conversation.
Broadcast::channel('chat.{groupId}.{userId1}.{userId2}', function (User $user, int $groupId, int $userId1, int $userId2) {
    if ($user->id !== $userId1 && $user->id !== $userId2) {
        return false;
    }

    $group = Group::find($groupId);

    if (! $group) {
        return false;
    }

    $other = User::find($user->id === $userId1 ? $userId2 : $userId1);

    return $other !== null && app(GroupAccess::class)->canChat($user, $group, $other);
});
