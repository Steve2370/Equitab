<?php

namespace App\Features\Group\Services;

final class GroupInvitationLinks
{
    /** Called inside the group's existing write lock on updates. */
    public function attributes(string $visibility, ?string $existingToken = null): array
    {
        $visibility = GroupVisibility::normalize($visibility);

        return [
            'visibility' => $visibility,
            // Becoming public revokes the link; becoming private again gets a new one.
            'invite_token' => $visibility === 'public' ? null : ($existingToken ?: bin2hex(random_bytes(16))),
        ];
    }
}
