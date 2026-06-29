<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\User;

Broadcast::channel('chat.{groupId}.{userId1}.{userId2}', function (User $user, int $groupId, int $userId1, int $userId2) {
    return $user->id === $userId1 || $user->id === $userId2;
});
