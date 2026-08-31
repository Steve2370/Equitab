<?php

namespace App\Features\Group\Repositories\Contracts;

use App\Models\Group;
use Illuminate\Pagination\LengthAwarePaginator;

interface GroupRepositoryInterface
{
    public function findById(int $id): ?Group;
    public function findByUuid(string $uuid): ?Group;
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator;
    public function create(array $data): Group;
    public function update(Group $group, array $data): Group;
    public function delete(Group $group): bool;
    public function findAvailable(): LengthAwarePaginator;
}
