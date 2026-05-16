<?php

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\OrganizationRepository;
use App\Support\Channel\ChannelContext;
use App\Support\DomainConstants;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class OrganizationManagementService
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly OrganizationParentOptionsResolver $parentOptionsResolver,
        private readonly OrganizationImplicitParentApplier $implicitParentApplier,
        private readonly ChannelContext $channelContext,
    ) {}

    public function listOrganizations(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        [$tenantIds, $organizationIds] = $this->resolveAccessScope($actor, $filters);

        return $this->organizationRepository->paginateFiltered($tenantIds, $organizationIds, $filters, $perPage);
    }

    /**
     * Valid parent organizations for the create form, scoped by tenant and role.
     *
     * @return list<array{id: int, tenant_id: int, type: string, display_name: string, legal_name: string|null, status: string}>
     */
    public function listParentOptions(User $actor, array $filters): array
    {
        return $this->parentOptionsResolver->resolve($actor, $filters);
    }

    public function createOrganization(User $actor, array $payload, ?string $ipAddress = null, ?string $userAgent = null): Organization
    {
        $payload['tenant_id'] = $this->resolveTenantId($actor, $payload);
        $this->implicitParentApplier->apply($actor, $payload);
        $payload['created_by_user_id'] = $actor->id;
        $payload['updated_by_user_id'] = $actor->id;
        $payload['metadata'] ??= [];

        $this->assertCreatePermission($actor, $payload);
        $this->assertParentRules($payload['tenant_id'], $payload['type'], $payload['parent_organization_id'] ?? null, $actor);
        $this->applyChannelMode($payload);

        $organization = $this->organizationRepository->create($payload);
        $this->audit(
            $actor,
            DomainConstants::LOG_ORGANIZATION_CREATED,
            $organization,
            null,
            $organization->toArray(),
            $ipAddress,
            $userAgent
        );

        return $organization;
    }

    public function showOrganization(User $actor, int $organizationId): Organization
    {
        $organization = $this->organizationRepository->findById($organizationId);
        if (! $organization || ! $this->canAccessOrganization($actor, $organization)) {
            throw new ModelNotFoundException(DomainConstants::MSG_ORGANIZATION_NOT_FOUND);
        }

        return $organization;
    }

    public function updateOrganization(User $actor, int $organizationId, array $payload, ?string $ipAddress = null, ?string $userAgent = null): Organization
    {
        $organization = $this->showOrganization($actor, $organizationId);
        $this->assertUpdatePermission($actor, $organization);

        $before = $organization->toArray();
        $payload['updated_by_user_id'] = $actor->id;

        $updated = $this->organizationRepository->update($organization, $payload);
        $this->audit(
            $actor,
            DomainConstants::LOG_ORGANIZATION_UPDATED,
            $updated,
            $before,
            $updated->toArray(),
            $ipAddress,
            $userAgent
        );

        return $updated;
    }

    public function updateStatus(User $actor, int $organizationId, string $status, ?string $ipAddress = null, ?string $userAgent = null): Organization
    {
        $organization = $this->showOrganization($actor, $organizationId);
        $this->assertUpdatePermission($actor, $organization);

        $before = $organization->toArray();
        $updated = $this->organizationRepository->update($organization, [
            'status' => $status,
            'updated_by_user_id' => $actor->id,
        ]);

        $this->audit(
            $actor,
            DomainConstants::LOG_ORGANIZATION_STATUS_CHANGED,
            $updated,
            $before,
            $updated->toArray(),
            $ipAddress,
            $userAgent
        );

        return $updated;
    }

    public function approve(User $actor, int $organizationId, ?string $ipAddress = null, ?string $userAgent = null): Organization
    {
        $organization = $this->showOrganization($actor, $organizationId);
        $this->assertApprovePermission($actor, $organization);

        if (! in_array($organization->onboarding_status, [Organization::ONBOARDING_DRAFT, Organization::ONBOARDING_PENDING_REVIEW], true)) {
            throw ValidationException::withMessages([
                'onboarding_status' => ['Only draft or pending review organizations can be approved.'],
            ]);
        }

        $before = $organization->toArray();
        $updated = $this->organizationRepository->update($organization, [
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'updated_by_user_id' => $actor->id,
        ]);

        $this->audit($actor, DomainConstants::LOG_ORGANIZATION_APPROVED, $updated, $before, $updated->toArray(), $ipAddress, $userAgent);

        return $updated;
    }

    public function reject(User $actor, int $organizationId, ?string $reason, ?string $ipAddress = null, ?string $userAgent = null): Organization
    {
        $organization = $this->showOrganization($actor, $organizationId);
        $this->assertApprovePermission($actor, $organization);

        $before = $organization->toArray();
        $metadata = (array) ($organization->metadata ?? []);
        if ($reason) {
            $metadata['rejection_reason'] = $reason;
        }

        $updated = $this->organizationRepository->update($organization, [
            'onboarding_status' => Organization::ONBOARDING_REJECTED,
            'status' => Organization::STATUS_INACTIVE,
            'metadata' => $metadata,
            'updated_by_user_id' => $actor->id,
        ]);

        $this->audit($actor, DomainConstants::LOG_ORGANIZATION_REJECTED, $updated, $before, $updated->toArray(), $ipAddress, $userAgent);

        return $updated;
    }

    public function suspend(User $actor, int $organizationId, ?string $ipAddress = null, ?string $userAgent = null): Organization
    {
        $organization = $this->showOrganization($actor, $organizationId);
        $this->assertApprovePermission($actor, $organization);

        $before = $organization->toArray();
        $updated = $this->organizationRepository->update($organization, [
            'onboarding_status' => Organization::ONBOARDING_SUSPENDED,
            'status' => Organization::STATUS_INACTIVE,
            'updated_by_user_id' => $actor->id,
        ]);

        $this->audit($actor, DomainConstants::LOG_ORGANIZATION_SUSPENDED, $updated, $before, $updated->toArray(), $ipAddress, $userAgent);

        return $updated;
    }

    private function assertCreatePermission(User $actor, array $payload): void
    {
        if (! $actor->isGlobalAdmin() && ! $actor->isCompanyAdmin() && $actor->currentRoleCode() === Role::CODE_PARTNER_ADMIN) {
            if ($payload['type'] !== Organization::TYPE_RESELLER) {
                throw ValidationException::withMessages([
                    'type' => ['Partner admin can only create reseller organizations.'],
                ]);
            }

            if ((int) ($payload['parent_organization_id'] ?? 0) !== (int) $actor->primaryOrganizationId()) {
                throw ValidationException::withMessages([
                    'parent_organization_id' => ['Partner admin can only create reseller under own partner organization.'],
                ]);
            }

            return;
        }

        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return;
        }

        throw ValidationException::withMessages([
            'organization' => ['You are not allowed to create organizations.'],
        ]);
    }

    private function assertUpdatePermission(User $actor, Organization $organization): void
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return;
        }

        if ($actor->currentRoleCode() === Role::CODE_PARTNER_ADMIN) {
            $allowedIds = $this->resolvePartnerScopeOrganizationIds((int) ($actor->primaryOrganizationId() ?? 0));
            if (in_array($organization->id, $allowedIds, true)) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'organization' => ['You are not allowed to manage this organization.'],
        ]);
    }

    private function assertApprovePermission(User $actor, Organization $organization): void
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return;
        }

        if (
            $actor->currentRoleCode() === Role::CODE_PARTNER_ADMIN
            && $organization->type === Organization::TYPE_RESELLER
            && (int) $organization->parent_organization_id === (int) ($actor->primaryOrganizationId() ?? 0)
        ) {
            return;
        }

        throw ValidationException::withMessages([
            'organization' => ['You are not allowed to approve or suspend this organization.'],
        ]);
    }

    private function assertParentRules(int $tenantId, string $type, ?int $parentId, User $actor): void
    {
        if ($type === Organization::TYPE_COMPANY && $parentId !== null) {
            throw ValidationException::withMessages([
                'parent_organization_id' => ['Company organization cannot have parent organization.'],
            ]);
        }

        if ($type !== Organization::TYPE_COMPANY && $parentId === null) {
            throw ValidationException::withMessages([
                'parent_organization_id' => ['Parent organization is required for partner and reseller.'],
            ]);
        }

        if ($parentId === null) {
            return;
        }

        $parent = $this->organizationRepository->findById($parentId);
        if (! $parent || (int) $parent->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'parent_organization_id' => ['Parent organization does not belong to tenant.'],
            ]);
        }

        if ($type === Organization::TYPE_PARTNER && $parent->type !== Organization::TYPE_COMPANY) {
            throw ValidationException::withMessages([
                'parent_organization_id' => ['Partner parent must be company organization.'],
            ]);
        }

        if ($type === Organization::TYPE_RESELLER
            && ! in_array($parent->type, [Organization::TYPE_PARTNER, Organization::TYPE_COMPANY], true)) {
            throw ValidationException::withMessages([
                'parent_organization_id' => ['Reseller parent must be a company or partner organization.'],
            ]);
        }

        if ($type === Organization::TYPE_RESELLER
            && $parent->type === Organization::TYPE_COMPANY
            && ! $actor->isGlobalAdmin()
            && ! $actor->isCompanyAdmin()) {
            throw ValidationException::withMessages([
                'parent_organization_id' => ['Only company administrators can create direct resellers under the company.'],
            ]);
        }

        if ($actor->currentRoleCode() === Role::CODE_PARTNER_ADMIN && (int) ($actor->primaryOrganizationId() ?? 0) !== $parent->id) {
            throw ValidationException::withMessages([
                'parent_organization_id' => ['Partner admin can only use own organization as parent.'],
            ]);
        }
    }

    /**
     * @return array{0: array<int>, 1: array<int>}
     */
    private function resolveAccessScope(User $actor, array $filters): array
    {
        $tenantIds = [(int) $actor->tenant_id];
        $organizationIds = [];

        if ($actor->isGlobalAdmin()) {
            $tenantIds = isset($filters['tenant_id']) ? [(int) $filters['tenant_id']] : [];
            $organizationIds = [];
        } elseif ($actor->isCompanyAdmin()) {
            $tenantIds = [(int) $actor->tenant_id];
            $organizationIds = [];
        } elseif ($actor->currentRoleCode() === Role::CODE_PARTNER_ADMIN) {
            $organizationIds = $this->resolvePartnerScopeOrganizationIds((int) ($actor->primaryOrganizationId() ?? 0));
        } elseif ($actor->isResellerRole()) {
            $organizationIds = [(int) ($actor->primaryOrganizationId() ?? 0)];
        }

        return [$tenantIds, $organizationIds];
    }

    /**
     * @return array<int>
     */
    private function resolvePartnerScopeOrganizationIds(int $partnerOrganizationId): array
    {
        if ($partnerOrganizationId <= 0) {
            return [];
        }

        $ids = [$partnerOrganizationId];
        foreach ($this->organizationRepository->descendantIds($partnerOrganizationId) as $descendantId) {
            $ids[] = (int) $descendantId;
        }

        return array_values(array_unique($ids));
    }

    private function canAccessOrganization(User $actor, Organization $organization): bool
    {
        $hasAccess = false;

        if ($actor->isGlobalAdmin()) {
            $hasAccess = true;
        } elseif ((int) $organization->tenant_id !== (int) $actor->tenant_id) {
            $hasAccess = false;
        } elseif ($actor->isCompanyAdmin()) {
            $hasAccess = true;
        } elseif ($actor->currentRoleCode() === Role::CODE_PARTNER_ADMIN) {
            $hasAccess = in_array($organization->id, $this->resolvePartnerScopeOrganizationIds((int) ($actor->primaryOrganizationId() ?? 0)), true);
        } elseif ($actor->isResellerRole()) {
            $hasAccess = (int) $organization->id === (int) ($actor->primaryOrganizationId() ?? 0);
        }

        return $hasAccess;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyChannelMode(array &$payload): void
    {
        if (($payload['type'] ?? '') !== Organization::TYPE_RESELLER) {
            return;
        }

        if (! empty($payload['channel_mode'])) {
            return;
        }

        $parentId = (int) ($payload['parent_organization_id'] ?? 0);
        if ($parentId <= 0) {
            $payload['channel_mode'] = Organization::CHANNEL_MODE_DIRECT;

            return;
        }

        $payload['channel_mode'] = $this->channelContext->inferChannelModeForReseller($parentId);
    }

    private function resolveTenantId(User $actor, array $payload): int
    {
        if ($actor->isGlobalAdmin()) {
            if (! isset($payload['tenant_id'])) {
                throw ValidationException::withMessages([
                    'tenant_id' => ['tenant_id is required for global admin operations.'],
                ]);
            }

            return (int) $payload['tenant_id'];
        }

        return (int) $actor->tenant_id;
    }

    private function audit(
        User $actor,
        string $action,
        Organization $organization,
        ?array $before,
        ?array $after,
        ?string $ipAddress,
        ?string $userAgent
    ): void {
        $this->auditLogRepository->create([
            'tenant_id' => $organization->tenant_id,
            'user_id' => $actor->id,
            'module' => 'organization',
            'action' => $action,
            'entity_type' => 'organization',
            'entity_id' => $organization->id,
            'before' => $before,
            'after' => $after,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }
}
