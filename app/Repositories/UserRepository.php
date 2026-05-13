<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UserRepository
{
    public function create(array $data): User
    {
        return User::query()->create($data);
    }

    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    public function findById(int $id): ?User
    {
        return User::query()->find($id);
    }

    public function paginateForCurrentScope(int $perPage = 15): LengthAwarePaginator
    {
        return User::query()
            ->with(['tenant', 'roleModel', 'organizationAssignment'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function update(User $user, array $data): User
    {
        $user->update($data);

        return $user->refresh();
    }
}
