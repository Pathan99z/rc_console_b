<?php

namespace App\Repositories;

use App\Models\OrganizationInvitation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OrganizationInvitationRepository
{
    public function create(array $data): OrganizationInvitation
    {
        return OrganizationInvitation::query()->create($data);
    }

    public function findByIdForOrganization(int $organizationId, int $invitationId): ?OrganizationInvitation
    {
        return OrganizationInvitation::query()
            ->where('organization_id', $organizationId)
            ->whereKey($invitationId)
            ->first();
    }

    public function findPendingByTokenHash(string $tokenHash): ?OrganizationInvitation
    {
        return OrganizationInvitation::query()
            ->where('token_hash', $tokenHash)
            ->where('status', OrganizationInvitation::STATUS_PENDING)
            ->first();
    }

    public function findPendingDuplicate(int $organizationId, string $email): ?OrganizationInvitation
    {
        return OrganizationInvitation::query()
            ->where('organization_id', $organizationId)
            ->where('email', strtolower($email))
            ->where('status', OrganizationInvitation::STATUS_PENDING)
            ->first();
    }

    public function paginateForOrganization(int $organizationId, int $perPage): LengthAwarePaginator
    {
        return OrganizationInvitation::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function update(OrganizationInvitation $invitation, array $data): OrganizationInvitation
    {
        $invitation->update($data);

        return $invitation->refresh();
    }
}
