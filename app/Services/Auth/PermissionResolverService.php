<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Access\PermissionProfileResolver;

class PermissionResolverService
{
    public function __construct(private readonly PermissionProfileResolver $profileResolver) {}

    /**
     * @return list<string>
     */
    public function roles(User $user): array
    {
        return $this->profileResolver->roles($user);
    }

    /**
     * @return list<string>
     */
    public function permissions(User $user): array
    {
        return $this->profileResolver->permissions($user);
    }

    public function can(User $user, string $permission): bool
    {
        return in_array($permission, $this->permissions($user), true);
    }

    /**
     * @param  list<string>  $permissions
     */
    public function canAny(User $user, array $permissions): bool
    {
        $granted = $this->permissions($user);
        foreach ($permissions as $permission) {
            if (in_array($permission, $granted, true)) {
                return true;
            }
        }

        return false;
    }

    public function navigationProfile(User $user): string
    {
        return $this->profileResolver->navigationProfile($user);
    }

    /**
     * @return array<string, bool>
     */
    public function featureFlags(User $user): array
    {
        return $this->profileResolver->featureFlags($user);
    }
}
