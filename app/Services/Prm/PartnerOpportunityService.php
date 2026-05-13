<?php

namespace App\Services\Prm;

use App\Models\Deal;
use App\Models\User;
use App\Services\Deal\DealManagementService;
use App\Support\DomainConstants;
use App\Support\PartnerScopeResolver;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class PartnerOpportunityService
{
    public function __construct(
        private readonly DealManagementService $dealManagementService,
        private readonly PartnerScopeResolver $partnerScopeResolver,
    ) {}

    /**
     * Registers a CRM deal on behalf of the partner channel with duplicate protection.
     *
     * @param  array<string, mixed>  $payload
     */
    public function registerOpportunity(User $actor, array $payload): Deal
    {
        if (! $actor->isPartnerPortalEligible()) {
            throw ValidationException::withMessages([
                'organization' => [DomainConstants::MSG_UNAUTHORIZED_SCOPE],
            ]);
        }

        $partnerOrgId = (int) ($actor->primaryOrganizationId() ?? 0);
        $allowed = $this->partnerScopeResolver->visibleChannelOrganizationIds($actor);
        if (! in_array($partnerOrgId, $allowed, true)) {
            throw ValidationException::withMessages([
                'organization' => [DomainConstants::MSG_UNAUTHORIZED_SCOPE],
            ]);
        }

        $fingerprint = $this->buildFingerprint(
            (int) $actor->tenant_id,
            $partnerOrgId,
            (string) ($payload['opportunity_key'] ?? ''),
            (string) ($payload['contact_email'] ?? ''),
            (int) ($payload['company_id'] ?? 0)
        );

        $payload['partner_organization_id'] = $partnerOrgId;
        $payload['partner_opportunity_fingerprint'] = $fingerprint;
        $payload['meta'] = array_merge(
            (array) ($payload['meta'] ?? []),
            [
                '_deprecated_mapping' => 'crm.deals',
                'source' => 'prm_partner_opportunity',
            ]
        );

        if (Deal::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('partner_organization_id', $partnerOrgId)
            ->where('partner_opportunity_fingerprint', $fingerprint)
            ->exists()) {
            throw ValidationException::withMessages([
                'opportunity_key' => [DomainConstants::MSG_PRM_DUPLICATE_OPPORTUNITY],
            ]);
        }

        try {
            return $this->dealManagementService->createDeal($actor, $payload);
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'duplicate')) {
                throw ValidationException::withMessages([
                    'opportunity_key' => [DomainConstants::MSG_PRM_DUPLICATE_OPPORTUNITY],
                ]);
            }
            throw $e;
        }
    }

    private function buildFingerprint(int $tenantId, int $partnerOrgId, string $opportunityKey, string $email, int $companyId): string
    {
        $normalized = strtolower(trim($opportunityKey)) !== ''
            ? strtolower(trim($opportunityKey))
            : strtolower(trim($email)).'|'.(string) $companyId;

        return hash('sha256', $tenantId.'|'.$partnerOrgId.'|'.$normalized);
    }
}
