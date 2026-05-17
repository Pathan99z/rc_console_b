<?php

namespace App\Services\Prm;

use App\Models\LicenseEntitlement;
use App\Models\Organization;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Services\Auth\AccessScopeService;
use App\Support\Audit\BusinessAuditEventKeys;
use App\Events\Notifications\LicenseAllocated;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class LicenseEntitlementService
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly AccessScopeService $accessScopeService,
    ) {}

    public function list(User $actor, int $perPage): LengthAwarePaginator
    {
        $q = LicenseEntitlement::query()->with(['holderOrganization', 'product'])->orderByDesc('id');
        if ($actor->isGlobalAdmin()) {
            return $q->paginate($perPage);
        }
        if ($actor->isCompanyAdmin()) {
            return $q->where('tenant_id', $actor->tenant_id)->paginate($perPage);
        }
        if ($actor->isPartnerPortalEligible()) {
            $orgIds = $this->accessScopeService->visibleChannelOrgIds($actor);
            if ($orgIds === []) {
                return $q->whereRaw('1 = 0')->paginate($perPage);
            }

            return $q->where('tenant_id', $actor->tenant_id)
                ->whereIn('holder_organization_id', $orgIds)
                ->paginate($perPage);
        }

        throw ValidationException::withMessages(['organization' => ['Not allowed.']]);
    }

    public function allocate(User $actor, array $payload, ?string $ip, ?string $ua): LicenseEntitlement
    {
        if (! $actor->isCompanyAdmin() && ! $actor->isGlobalAdmin()) {
            throw ValidationException::withMessages(['organization' => ['Only company administrators can allocate licenses.']]);
        }

        $holder = Organization::query()->whereKey((int) $payload['holder_organization_id'])->first();
        if (! $holder || (int) $holder->tenant_id !== (int) $actor->tenant_id) {
            throw ValidationException::withMessages(['holder_organization_id' => ['Invalid holder organization.']]);
        }

        $row = LicenseEntitlement::query()->create([
            'tenant_id' => $holder->tenant_id,
            'holder_organization_id' => $holder->id,
            'parent_entitlement_id' => $payload['parent_entitlement_id'] ?? null,
            'product_id' => $payload['product_id'] ?? null,
            'units_total' => (int) $payload['units_total'],
            'units_consumed' => 0,
            'notes' => $payload['notes'] ?? null,
            'created_by_user_id' => $actor->id,
            'metadata' => $payload['metadata'] ?? null,
        ]);

        $this->auditLogRepository->create([
            'tenant_id' => $row->tenant_id,
            'user_id' => $actor->id,
            'module' => 'prm',
            'action' => 'prm.license.allocated',
            'entity_type' => 'license_entitlement',
            'entity_id' => $row->id,
            'before' => null,
            'after' => $row->toArray(),
            'ip_address' => $ip,
            'user_agent' => $ua,
            'event_key' => BusinessAuditEventKeys::LICENSES_ALLOCATED,
        ]);

        event(new LicenseAllocated($row->id, $actor->id));

        return $row;
    }

    public function consume(User $actor, int $entitlementId, int $units, ?string $ip, ?string $ua): LicenseEntitlement
    {
        if (! $actor->isPartnerPortalEligible() && ! $actor->isCompanyAdmin()) {
            throw ValidationException::withMessages(['organization' => ['Not allowed.']]);
        }

        $row = LicenseEntitlement::query()->whereKey($entitlementId)->first();
        if (! $row || (int) $row->tenant_id !== (int) $actor->tenant_id) {
            throw new ModelNotFoundException('License entitlement not found.');
        }

        if ($actor->isPartnerPortalEligible() && ! in_array((int) $row->holder_organization_id, $this->accessScopeService->visibleChannelOrgIds($actor), true)) {
            throw new ModelNotFoundException('License entitlement not found.');
        }

        $before = $row->toArray();
        $next = (int) $row->units_consumed + $units;
        if ($next > (int) $row->units_total) {
            throw ValidationException::withMessages(['units' => ['Consumption exceeds allocated units.']]);
        }
        $row->update(['units_consumed' => $next]);
        $fresh = $row->refresh();

        $this->auditLogRepository->create([
            'tenant_id' => $fresh->tenant_id,
            'user_id' => $actor->id,
            'module' => 'prm',
            'action' => 'prm.license.consumed',
            'entity_type' => 'license_entitlement',
            'entity_id' => $fresh->id,
            'before' => $before,
            'after' => $fresh->toArray(),
            'ip_address' => $ip,
            'user_agent' => $ua,
        ]);

        return $fresh;
    }
}
