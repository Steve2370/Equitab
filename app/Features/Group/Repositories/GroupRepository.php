<?php

namespace App\Features\Group\Repositories;

use App\Models\Group;
use App\Features\Group\Repositories\Contracts\GroupRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class GroupRepository implements GroupRepositoryInterface
{
    public function findById(int $id): ?Group
    {
        return Group::find($id);
    }

    public function findByUuid(string $uuid): ?Group
    {
        return Group::where('uuid', $uuid)->first();
    }

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Group::with(['subscription', 'owner'])
            ->when(isset($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['subscription_id']), fn($q) => $q->where('subscription_id', $filters['subscription_id']));

        return $query->paginate($perPage);
    }

    public function create(array $data): Group
    {
        return Group::create($data);
    }

    public function update(Group $group, array $data): Group
    {
        $group->update($data);
        return $group->fresh();
    }

    public function delete(Group $group): bool
    {
        return $group->delete();
    }

    public function findAvailable(): LengthAwarePaginator
    {
        return Group::with(['subscription', 'owner'])
            ->where('status', 'open')
            ->where('visibility', 'public')
            ->whereColumn('current_members', '<', 'max_members')
            ->paginate(15);
    }
}
