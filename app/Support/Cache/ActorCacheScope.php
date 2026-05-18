<?php

declare(strict_types=1);

namespace App\Support\Cache;

use App\Models\User;
use App\Support\PartnerScopeResolver;

/**
 * Builds a stable, non-PII fingerprint of the actor's data visibility for cache keys.
 */
final class ActorCacheScope
{
    public function __construct(private readonly PartnerScopeResolver $partnerScopeResolver) {}

    public function fingerprint(User $actor): string
    {
        if ($actor->isGlobalAdmin()) {
            return hash('sha256', 'global|'.$actor->currentRoleCode());
        }

        if ($actor->isCompanyAdmin() || $actor->isFinanceAdmin()) {
            return hash('sha256', 'tenant-admin|'.(int) $actor->tenant_id.'|'.$actor->currentRoleCode());
        }

        $channelOrgIds = $this->partnerScopeResolver->visibleChannelOrganizationIds($actor);
        sort($channelOrgIds);

        return hash('sha256', implode('|', [
            'scoped',
            (int) $actor->tenant_id,
            (int) $actor->id,
            $actor->currentRoleCode(),
            (int) $actor->data_scope,
            (int) ($actor->team_id ?? 0),
            implode(',', $channelOrgIds),
        ]));
    }
}
