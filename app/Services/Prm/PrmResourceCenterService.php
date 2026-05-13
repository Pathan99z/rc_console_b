<?php

namespace App\Services\Prm;

use App\Models\Collateral;
use App\Models\CollateralDownload;
use App\Models\User;
use App\Services\Auth\PermissionResolverService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class PrmResourceCenterService
{
    public function __construct(private readonly PermissionResolverService $permissionResolver) {}

    public function listForPartner(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        if (! $this->permissionResolver->can($actor, 'prm.resources.view')) {
            throw ValidationException::withMessages(['resource' => ['Not allowed to access partner resources.']]);
        }

        $category = isset($filters['resource_category']) ? (string) $filters['resource_category'] : null;

        return Collateral::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('partner_visible', true)
            ->when($category, fn ($q) => $q->where('resource_category', $category))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function recordDownload(User $actor, int $collateralId, ?string $ip, ?string $ua): void
    {
        if (! $this->permissionResolver->can($actor, 'prm.resources.view')) {
            return;
        }

        $c = Collateral::query()->whereKey($collateralId)->where('tenant_id', $actor->tenant_id)->first();
        if (! $c || ! $c->partner_visible) {
            return;
        }

        CollateralDownload::query()->create([
            'tenant_id' => (int) $actor->tenant_id,
            'collateral_id' => $c->id,
            'user_id' => $actor->id,
            'partner_organization_id' => $actor->primaryOrganizationId(),
            'ip_address' => $ip,
            'user_agent' => $ua,
        ]);
    }
}
