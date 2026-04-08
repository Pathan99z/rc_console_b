<?php

namespace App\Repositories;

use App\Models\Role;

class RoleRepository
{
    public function findByCode(string $code): ?Role
    {
        return Role::query()->where('code', $code)->first();
    }
}
