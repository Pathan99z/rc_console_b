<?php

namespace App\Support\Channel;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Repositories\OrganizationRepository;

/**
 * Resolves channel organization stamping and commercial parent relationships.
 */
readonly class ChannelContext
{
    public function __construct(private OrganizationRepository $organizationRepository) {}

    /**
     * Primary channel org id for ABAC stamping (null for RC internal users).
     */
    public function resolveStampOrganizationId(User $actor): ?int
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin() || $actor->currentRoleCode() === Role::CODE_USER) {
            return null;
        }

        if ($actor->isPartnerChannelUser() || $actor->isResellerRole()) {
            $orgId = $actor->primaryOrganizationId();

            return $orgId !== null && $orgId > 0 ? $orgId : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function stampPayload(User $actor, array &$payload): void
    {
        $orgId = $this->resolveStampOrganizationId($actor);
        if ($orgId === null) {
            return;
        }

        if (! array_key_exists('channel_organization_id', $payload) || $payload['channel_organization_id'] === null) {
            $payload['channel_organization_id'] = $orgId;
        }

        // partner_organization_id exists only on commercial entities (deals); deal service sets it explicitly.
    }

    public function resolvePartnerManagedParentPartnerId(Organization $reseller): ?int
    {
        if ($reseller->type !== Organization::TYPE_RESELLER) {
            return null;
        }

        if ($reseller->channel_mode === Organization::CHANNEL_MODE_DIRECT) {
            return null;
        }

        $parentId = (int) ($reseller->parent_organization_id ?? 0);
        if ($parentId <= 0) {
            return null;
        }

        $parent = $this->organizationRepository->findById($parentId);

        return $parent && $parent->type === Organization::TYPE_PARTNER ? (int) $parent->id : null;
    }

    public function inferChannelModeForReseller(int $parentOrganizationId): string
    {
        $parent = $this->organizationRepository->findById($parentOrganizationId);
        if (! $parent) {
            return Organization::CHANNEL_MODE_DIRECT;
        }

        return $parent->type === Organization::TYPE_PARTNER
            ? Organization::CHANNEL_MODE_PARTNER_MANAGED
            : Organization::CHANNEL_MODE_DIRECT;
    }
}
