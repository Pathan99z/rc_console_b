<?php

namespace App\Repositories;

use App\Models\PartnerLead;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PartnerLeadRepository
{
    public function create(array $data): PartnerLead
    {
        return PartnerLead::query()->create($data);
    }

    public function findByIdForPartner(int $leadId, int $partnerOrganizationId): ?PartnerLead
    {
        return PartnerLead::query()
            ->whereKey($leadId)
            ->where('partner_organization_id', $partnerOrganizationId)
            ->first();
    }

    public function paginateForPartner(User $actor, int $partnerOrganizationId, int $perPage): LengthAwarePaginator
    {
        return PartnerLead::query()
            ->where('partner_organization_id', $partnerOrganizationId)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function update(PartnerLead $lead, array $data): PartnerLead
    {
        $lead->update($data);

        return $lead->refresh();
    }
}
