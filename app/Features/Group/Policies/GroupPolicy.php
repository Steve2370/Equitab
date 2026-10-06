<?php

namespace App\Features\Group\Policies;

use App\Features\Group\Services\OwnerPublicationEligibility;
use App\Models\Group;
use App\Models\User;

class GroupPolicy
{
    public function __construct(private readonly OwnerPublicationEligibility $eligibility) {}

    public function create(User $user): bool
    {
        return $this->eligibility->state($user)['ready'];
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
