<?php

namespace App\Repositories;

use App\Models\Contact;
use App\Models\User;
use App\Services\Auth\AccessScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ContactRepository
{
    public function __construct(private readonly AccessScopeService $accessScopeService) {}

    public function paginateFiltered(User $actor, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = Contact::query()
            ->with(['company', 'assignedUser', 'createdByUser', 'updatedByUser'])
            ->when(! $actor->isGlobalAdmin(), fn (Builder $q) => $q->where('tenant_id', $actor->tenant_id))
            ->when($actor->isGlobalAdmin() && isset($filters['tenant_id']), fn (Builder $q) => $q->where('tenant_id', (int) $filters['tenant_id']))
            ->when(isset($filters['stage']), fn (Builder $q) => $q->where('lifecycle_stage', (int) $filters['stage']))
            ->when(isset($filters['owner_id']), fn (Builder $q) => $q->where('assigned_user_id', (int) $filters['owner_id']))
            ->when(isset($filters['company_id']), fn (Builder $q) => $q->where('company_id', (int) $filters['company_id']))
            ->when(isset($filters['search']), function (Builder $q) use ($filters): void {
                $search = (string) $filters['search'];
                $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });

        $this->applyVisibilityScope($query, $actor);

        return $query->orderByDesc('id')->paginate($perPage);
    }

    public function findById(int $id): ?Contact
    {
        return Contact::query()->with(['company', 'assignedUser', 'createdByUser', 'updatedByUser', 'activities.user'])->find($id);
    }

    public function create(array $data): Contact
    {
        return Contact::query()->create($data);
    }

    public function update(Contact $contact, array $data): Contact
    {
        $contact->update($data);

        return $contact->refresh();
    }

    public function delete(Contact $contact): void
    {
        $contact->delete();
    }

    public function queryForExport(User $actor, array $filters): Builder
    {
        $query = Contact::query()
            ->with(['company', 'assignedUser', 'createdByUser', 'updatedByUser'])
            ->when(! $actor->isGlobalAdmin(), fn (Builder $q) => $q->where('tenant_id', $actor->tenant_id))
            ->when($actor->isGlobalAdmin() && isset($filters['tenant_id']), fn (Builder $q) => $q->where('tenant_id', (int) $filters['tenant_id']))
            ->when(isset($filters['stage']), fn (Builder $q) => $q->where('lifecycle_stage', (int) $filters['stage']))
            ->when(isset($filters['owner_id']), fn (Builder $q) => $q->where('assigned_user_id', (int) $filters['owner_id']))
            ->when(isset($filters['company_id']), fn (Builder $q) => $q->where('company_id', (int) $filters['company_id']))
            ->when(isset($filters['search']), function (Builder $q) use ($filters): void {
                $search = (string) $filters['search'];
                $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });

        $this->applyVisibilityScope($query, $actor);

        return $query;
    }

    public function emailExistsForTenant(int $tenantId, string $email, ?int $ignoreContactId = null): bool
    {
        return Contact::query()
            ->where('tenant_id', $tenantId)
            ->where('email', strtolower($email))
            ->whereNull('deleted_at')
            ->when($ignoreContactId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreContactId))
            ->exists();
    }

    private function applyVisibilityScope(Builder $query, User $actor): void
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return;
        }

        $channelOrgIds = $this->accessScopeService->visibleChannelOrgIds($actor);

        $query->where(function (Builder $inner) use ($actor, $channelOrgIds): void {
            $this->accessScopeService->applyOwnerTeamScope($inner, $actor, 'assigned_user_id', 'created_by_user_id');

            if ($channelOrgIds !== []) {
                $inner->orWhereIn('id', function ($sub) use ($actor, $channelOrgIds): void {
                    $sub->from('deals')
                        ->select('contact_id')
                        ->where('tenant_id', $actor->tenant_id)
                        ->whereNotNull('contact_id')
                        ->whereIn('partner_organization_id', $channelOrgIds);
                });
            }
        });
    }
}
