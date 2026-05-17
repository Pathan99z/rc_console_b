<?php

namespace App\Services\Prm;

use App\Models\LicenseActivation;
use App\Models\LicenseEntitlement;
use App\Models\LicenseMovement;
use App\Models\Organization;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\OrganizationRepository;
use App\Services\Auth\AccessScopeService;
use App\Support\Audit\BusinessAuditEventKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Events\Notifications\LicenseAllocated;
use App\Events\Notifications\LicenseActivatedEvent;

class LicenseLedgerService
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly AccessScopeService $accessScopeService,
        private readonly OrganizationRepository $organizationRepository,
    ) {}

    /**
     * Partner transfers stock to child reseller (or RC allocates without parent decrement).
     */
    public function transfer(User $actor, array $payload, ?string $ip, ?string $ua): LicenseEntitlement
    {
        return DB::transaction(function () use ($actor, $payload, $ip, $ua): LicenseEntitlement {
            $fromId = (int) $payload['from_entitlement_id'];
            $toOrgId = (int) $payload['to_organization_id'];
            $units = (int) $payload['units'];

            $from = LicenseEntitlement::query()->whereKey($fromId)->lockForUpdate()->first();
            if (! $from || (int) $from->tenant_id !== (int) $actor->tenant_id) {
                throw ValidationException::withMessages(['from_entitlement_id' => ['Invalid source entitlement.']]);
            }

            $this->assertCanAccessEntitlement($actor, $from);
            $available = (int) $from->units_total - (int) $from->units_consumed;
            if ($units < 1 || $units > $available) {
                throw ValidationException::withMessages(['units' => ['Insufficient units available for transfer.']]);
            }

            $toOrg = Organization::query()->whereKey($toOrgId)->first();
            if (! $toOrg || (int) $toOrg->tenant_id !== (int) $from->tenant_id) {
                throw ValidationException::withMessages(['to_organization_id' => ['Invalid destination organization.']]);
            }

            if ($actor->isPartnerAdmin()) {
                $tree = $this->organizationRepository->channelTreeOrganizationIds((int) ($actor->primaryOrganizationId() ?? 0));
                if ($toOrg->type !== Organization::TYPE_RESELLER || ! in_array($toOrgId, $tree, true)) {
                    throw ValidationException::withMessages(['to_organization_id' => ['Can only transfer to child reseller organizations.']]);
                }
            } elseif (! $actor->isCompanyAdmin() && ! $actor->isGlobalAdmin()) {
                throw ValidationException::withMessages(['organization' => ['Not allowed to transfer licenses.']]);
            }

            $from->update(['units_consumed' => (int) $from->units_consumed + $units]);

            $child = LicenseEntitlement::query()->create([
                'tenant_id' => $from->tenant_id,
                'holder_organization_id' => $toOrgId,
                'parent_entitlement_id' => $from->id,
                'product_id' => $from->product_id,
                'units_total' => $units,
                'units_consumed' => 0,
                'notes' => $payload['notes'] ?? null,
                'created_by_user_id' => $actor->id,
                'metadata' => $payload['metadata'] ?? null,
            ]);

            $movement = LicenseMovement::query()->create([
                'tenant_id' => $from->tenant_id,
                'from_entitlement_id' => $from->id,
                'to_entitlement_id' => $child->id,
                'to_organization_id' => $toOrgId,
                'movement_type' => LicenseMovement::TYPE_TRANSFER,
                'units' => $units,
                'actor_user_id' => $actor->id,
                'reference' => $payload['reference'] ?? null,
                'metadata' => $payload['metadata'] ?? null,
            ]);

            $this->audit($actor, $from->tenant_id, 'prm.license.transferred', $child->id, null, [
                'movement_id' => $movement->id,
                'from_entitlement_id' => $from->id,
                'units' => $units,
            ], $ip, $ua);

            event(new LicenseAllocated($child->id, $actor->id));

            return $child->load(['holderOrganization', 'product']);
        });
    }

    public function activateToCustomer(User $actor, int $entitlementId, array $payload, ?string $ip, ?string $ua): LicenseActivation
    {
        return DB::transaction(function () use ($actor, $entitlementId, $payload, $ip, $ua): LicenseActivation {
            $row = LicenseEntitlement::query()->whereKey($entitlementId)->lockForUpdate()->first();
            if (! $row || (int) $row->tenant_id !== (int) $actor->tenant_id) {
                throw ValidationException::withMessages(['entitlement' => ['License entitlement not found.']]);
            }

            $this->assertCanAccessEntitlement($actor, $row);
            if (! $actor->isResellerRole() && ! $actor->isPartnerPortalEligible() && ! $actor->isCompanyAdmin()) {
                throw ValidationException::withMessages(['organization' => ['Not allowed.']]);
            }

            $units = (int) $payload['units'];
            $available = (int) $row->units_total - (int) $row->units_consumed;
            if ($units < 1 || $units > $available) {
                throw ValidationException::withMessages(['units' => ['Insufficient units available for activation.']]);
            }

            $row->update(['units_consumed' => (int) $row->units_consumed + $units]);

            $movement = LicenseMovement::query()->create([
                'tenant_id' => $row->tenant_id,
                'from_entitlement_id' => $row->id,
                'to_entitlement_id' => null,
                'to_organization_id' => null,
                'movement_type' => LicenseMovement::TYPE_ACTIVATE,
                'units' => $units,
                'actor_user_id' => $actor->id,
                'reference' => $payload['reference'] ?? null,
                'metadata' => $payload['metadata'] ?? null,
            ]);

            $activation = LicenseActivation::query()->create([
                'tenant_id' => $row->tenant_id,
                'license_entitlement_id' => $row->id,
                'license_movement_id' => $movement->id,
                'contact_id' => $payload['contact_id'] ?? null,
                'company_id' => $payload['company_id'] ?? null,
                'units' => $units,
                'activated_by_user_id' => $actor->id,
                'metadata' => $payload['metadata'] ?? null,
            ]);

            $this->audit($actor, $row->tenant_id, 'prm.license.activated', $row->id, null, [
                'movement_id' => $movement->id,
                'activation_id' => $activation->id,
                'units' => $units,
            ], $ip, $ua);

            event(new LicenseActivatedEvent($row->id, $actor->id, $units, $activation->id));

            return $activation->load(['entitlement']);
        });
    }

    private function assertCanAccessEntitlement(User $actor, LicenseEntitlement $row): void
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return;
        }

        $orgIds = $this->accessScopeService->visibleChannelOrgIds($actor);
        if (! in_array((int) $row->holder_organization_id, $orgIds, true)) {
            throw ValidationException::withMessages(['organization' => ['Not allowed to access this entitlement.']]);
        }
    }

    private function audit(
        User $actor,
        int $tenantId,
        string $action,
        int $entityId,
        ?array $before,
        ?array $after,
        ?string $ip,
        ?string $ua
    ): void {
        $this->auditLogRepository->create([
            'tenant_id' => $tenantId,
            'user_id' => $actor->id,
            'module' => 'prm',
            'action' => $action,
            'entity_type' => 'license_entitlement',
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'event_key' => match ($action) {
                'prm.license.transferred' => BusinessAuditEventKeys::LICENSES_TRANSFERRED,
                'prm.license.activated' => BusinessAuditEventKeys::LICENSES_ACTIVATED,
                default => null,
            },
        ]);
    }
}
