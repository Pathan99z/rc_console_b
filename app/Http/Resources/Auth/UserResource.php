<?php

namespace App\Http\Resources\Auth;

use App\Models\User;
use App\Services\Auth\AccountService;
use App\Services\Auth\PermissionResolverService;
use App\Support\Access\PermissionProfileResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profileResolver = app(PermissionProfileResolver::class);
        $permissionResolver = app(PermissionResolverService::class);
        $organization = $profileResolver->organization($this->resource);
        $orgModel = $this->organizationAssignment?->organization;

        if ($orgModel !== null) {
            $organization['display_name'] = $orgModel->display_name ?: $orgModel->legal_name;
            $organization['legal_name'] = $orgModel->legal_name;
        }

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'tenant' => $this->tenant ? [
                'id' => $this->tenant->id,
                'name' => $this->tenant->name,
            ] : null,
            'organization_id' => $this->primaryOrganizationId(),
            'team_id' => $this->team_id,
            'data_scope' => $this->dataScopeLabel(),
            'role' => $this->roleModel?->code ?? $this->role,
            'roles' => $permissionResolver->roles($this->resource),
            'permissions' => $permissionResolver->permissions($this->resource),
            'organization' => $organization,
            'organization_role' => $this->roleModel?->code ?? $this->role,
            'navigation_profile' => $permissionResolver->navigationProfile($this->resource),
            'feature_flags' => $permissionResolver->featureFlags($this->resource),
            'status' => $this->statusLabel(),
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'last_login_at' => AccountService::resolveLastLoginAt($this->resource),
            'created_at' => $this->created_at,
        ];
    }
}
