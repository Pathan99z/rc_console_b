<?php

namespace App\Support\Dashboard;

use App\Models\Organization;

/**
 * Immutable dashboard visibility scope for a target organization.
 */
readonly class DashboardScope
{
    /**
     * @param  list<int>  $organizationIds
     */
    public function __construct(
        public int $tenantId,
        public int $rootOrganizationId,
        public Organization $organization,
        public array $organizationIds,
        public bool $includesChildren,
    ) {}

    public function organizationType(): string
    {
        return (string) $this->organization->type;
    }

    public function channelMode(): ?string
    {
        return $this->organization->channel_mode;
    }
}
