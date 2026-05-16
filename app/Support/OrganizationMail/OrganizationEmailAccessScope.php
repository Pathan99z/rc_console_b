<?php

namespace App\Support\OrganizationMail;

use App\Models\Organization;
use App\Models\User;
use App\Repositories\OrganizationRepository;
use App\Support\DomainConstants;
use Illuminate\Validation\ValidationException;

class OrganizationEmailAccessScope
{
    public function __construct(private readonly OrganizationRepository $organizationRepository) {}

    /**
     * Organization IDs this actor may configure email settings for.
     *
     * @return list<int>
     */
    public function manageableOrganizationIds(User $actor): array
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return Organization::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $actor->tenant_id)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($actor->isPartnerAdmin()) {
            $partnerOrgId = (int) ($actor->primaryOrganizationId() ?? 0);

            return $partnerOrgId > 0
                ? $this->organizationRepository->channelTreeOrganizationIds($partnerOrgId)
                : [];
        }

        if ($actor->currentRoleCode() === \App\Models\Role::CODE_RESELLER_ADMIN) {
            $rid = (int) ($actor->primaryOrganizationId() ?? 0);

            return $rid > 0 ? [$rid] : [];
        }

        return [];
    }

    public function assertOrganizationEmailAccessible(User $actor, int $organizationId): void
    {
        $org = $this->organizationRepository->findByIdInTenant($organizationId, (int) $actor->tenant_id);
        if (! $org) {
            throw ValidationException::withMessages([
                'organization_id' => ['Invalid organization.'],
            ]);
        }

        $allowed = array_flip($this->manageableOrganizationIds($actor));
        if (! isset($allowed[$organizationId])) {
            throw ValidationException::withMessages([
                'organization_id' => [DomainConstants::MSG_UNAUTHORIZED_SCOPE],
            ]);
        }
    }
}
