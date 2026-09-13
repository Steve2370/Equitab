<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Group;
use App\Models\User;

// Vérifiait uniquement que l'utilisateur connecté correspondait à l'un des
// deux identifiants du nom du canal, jamais qu'il appartenait réellement au
// groupe {groupId} — n'importe quel utilisateur pouvait donc s'abonner à
// n'importe quel canal chat.{n'importe quel groupe}.{son propre id}.{un
// autre id}, y compris un groupe qu'il a quitté ou dont il n'a jamais fait
// partie. On vérifie maintenant une appartenance active réelle (propriétaire
// ou membre actif) au groupe concerné, en plus de la correspondance d'id.
Broadcast::channel('chat.{groupId}.{userId1}.{userId2}', function (User $user, int $groupId, int $userId1, int $userId2) {
    if ($user->id !== $userId1 && $user->id !== $userId2) {
        return false;
    }

    $group = Group::find($groupId);

    if (! $group) {
        return false;
    }

    if ($group->owner_id === $user->id) {
        return true;
    }

    return $group->members()
        ->where('user_id', $user->id)
        ->where('status', 'active')
        ->exists();
});
