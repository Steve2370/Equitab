<?php

namespace App\Features\Group\Policies;

use App\Models\Group;
use App\Models\User;

class GroupPolicy
{
    public function create(User $user): bool
    {
        return $user->identity_status === 'verified'
            && $user->stripe_connect_status === 'active';
    }

    public function update(User $user, Group $group): bool
    {
        return $user->id === $group->owner_id;
    }

    public function delete(User $user, Group $group): bool
    {
        return $user->id === $group->owner_id;
    }

    public function join(User $user, Group $group): bool
    {
        return $user->id !== $group->owner_id
            && $group->status === 'open'
            && ! $group->isFull();
    }
}
