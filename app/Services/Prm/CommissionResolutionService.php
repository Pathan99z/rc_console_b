<?php

namespace App\Services\Prm;

use App\Models\Deal;
use App\Models\Organization;
use App\Models\PartnerProgramEnrollment;
use App\Repositories\OrganizationRepository;
use App\Support\Channel\ChannelContext;

readonly class CommissionResolutionService
{
    public function __construct(
        private OrganizationRepository $organizationRepository,
        private ChannelContext $channelContext,
    ) {}

    /**
     * @return array{
     *   beneficiary_organization_id: int,
     *   enrollment: PartnerProgramEnrollment|null,
     *   commission_percent: float,
     *   resolution_mode: string
     * }
     */
    public function resolveForDeal(Deal $deal): array
    {
        $orgId = (int) ($deal->channel_organization_id ?? $deal->partner_organization_id ?? 0);
        if ($orgId <= 0) {
            return [
                'beneficiary_organization_id' => 0,
                'enrollment' => null,
                'commission_percent' => 0.0,
                'resolution_mode' => 'none',
            ];
        }

        $organization = $this->organizationRepository->findById($orgId);
        if (! $organization) {
            return [
                'beneficiary_organization_id' => $orgId,
                'enrollment' => null,
                'commission_percent' => 0.0,
                'resolution_mode' => 'unknown_org',
            ];
        }

        if ($organization->type === Organization::TYPE_PARTNER) {
            $enrollment = $this->activeEnrollment($deal->tenant_id, $organization->id);

            return [
                'beneficiary_organization_id' => $organization->id,
                'enrollment' => $enrollment,
                'commission_percent' => $this->percentFromEnrollment($enrollment),
                'resolution_mode' => 'partner_direct',
            ];
        }

        if ($organization->type === Organization::TYPE_RESELLER) {
            if ($organization->channel_mode === Organization::CHANNEL_MODE_DIRECT) {
                $enrollment = $this->activeEnrollment($deal->tenant_id, $organization->id);

                return [
                    'beneficiary_organization_id' => $organization->id,
                    'enrollment' => $enrollment,
                    'commission_percent' => $this->percentFromEnrollment($enrollment),
                    'resolution_mode' => 'reseller_direct',
                ];
            }

            $parentPartnerId = $this->channelContext->resolvePartnerManagedParentPartnerId($organization);
            if ($parentPartnerId === null) {
                return [
                    'beneficiary_organization_id' => $organization->id,
                    'enrollment' => null,
                    'commission_percent' => 0.0,
                    'resolution_mode' => 'reseller_no_parent_partner',
                ];
            }

            $enrollment = $this->activeEnrollment($deal->tenant_id, $parentPartnerId);

            return [
                'beneficiary_organization_id' => $organization->id,
                'enrollment' => $enrollment,
                'commission_percent' => $this->percentFromEnrollment($enrollment),
                'resolution_mode' => 'reseller_inherit_partner',
            ];
        }

        return [
            'beneficiary_organization_id' => $orgId,
            'enrollment' => null,
            'commission_percent' => 0.0,
            'resolution_mode' => 'unsupported_org_type',
        ];
    }

    private function activeEnrollment(int $tenantId, int $organizationId): ?PartnerProgramEnrollment
    {
        return PartnerProgramEnrollment::query()
            ->with('program')
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->where('status', PartnerProgramEnrollment::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();
    }

    private function percentFromEnrollment(?PartnerProgramEnrollment $enrollment): float
    {
        if (! $enrollment) {
            return 0.0;
        }

        if ($enrollment->commission_percent !== null) {
            return (float) $enrollment->commission_percent;
        }

        return (float) ($enrollment->program?->default_commission_percent ?? 0);
    }
}
