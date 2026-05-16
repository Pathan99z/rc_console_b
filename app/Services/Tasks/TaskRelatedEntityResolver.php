<?php

namespace App\Services\Tasks;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\LicenseEntitlement;
use App\Models\PaymentRecord;
use App\Models\Payout;
use App\Models\Quote;
use App\Models\Task;
use App\Models\User;
use App\Services\Auth\AccessScopeService;
use App\Support\Prm\PayoutAccessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class TaskRelatedEntityResolver
{
    public function __construct(
        private readonly AccessScopeService $accessScopeService,
        private readonly PayoutAccessScope $payoutAccessScope,
    ) {}

    /**
     * @return array{type: string, id: int|null, summary: string|null, label: string|null}|null
     */
    public function validateRelated(User $actor, ?string $relatedType, ?int $relatedId): ?array
    {
        if ($relatedType === null || $relatedType === '') {
            return null;
        }

        if (! in_array($relatedType, Task::relatedTypes(), true)) {
            throw ValidationException::withMessages([
                'related_type' => ['Invalid related entity type.'],
            ]);
        }

        if ($relatedType === Task::RELATED_OTHER) {
            return [
                'type' => Task::RELATED_OTHER,
                'id' => $relatedId,
                'summary' => null,
                'label' => 'Other',
            ];
        }

        if ($relatedId === null || $relatedId <= 0) {
            throw ValidationException::withMessages([
                'related_id' => ['Related entity id is required.'],
            ]);
        }

        $entity = $this->loadEntity($relatedType, $relatedId, $actor);
        if (! $entity) {
            throw new ModelNotFoundException('Related entity not found.');
        }

        $this->assertActorCanAccessEntity($actor, $relatedType, $entity);

        return [
            'type' => $relatedType,
            'id' => $relatedId,
            'summary' => $this->buildSummary($relatedType, $entity),
            'label' => $this->buildLabel($relatedType, $entity),
        ];
    }

    /**
     * @return array{type: string, id: int|null, summary: string|null, label: string|null}|null
     */
    public function summarize(Task $task): ?array
    {
        if ($task->related_type === null) {
            return null;
        }

        if ($task->related_type === Task::RELATED_OTHER) {
            return [
                'type' => Task::RELATED_OTHER,
                'id' => $task->related_id,
                'summary' => null,
                'label' => 'Other',
            ];
        }

        if ($task->related_id === null) {
            return null;
        }

        $entity = $this->loadEntity($task->related_type, (int) $task->related_id, null, (int) $task->tenant_id);
        if (! $entity) {
            return [
                'type' => $task->related_type,
                'id' => $task->related_id,
                'summary' => null,
                'label' => null,
            ];
        }

        return [
            'type' => $task->related_type,
            'id' => $task->related_id,
            'summary' => $this->buildSummary($task->related_type, $entity),
            'label' => $this->buildLabel($task->related_type, $entity),
        ];
    }

    private function loadEntity(string $type, int $id, ?User $actor = null, ?int $tenantId = null): ?Model
    {
        $tenantId ??= $actor?->tenant_id;

        return match ($type) {
            Task::RELATED_CONTACT => Contact::query()
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->find($id),
            Task::RELATED_COMPANY => Company::query()
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->find($id),
            Task::RELATED_DEAL => Deal::query()
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->find($id),
            Task::RELATED_QUOTE => Quote::query()
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->find($id),
            Task::RELATED_PAYMENT_RECORD => PaymentRecord::query()
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->find($id),
            Task::RELATED_PAYOUT => Payout::query()
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->find($id),
            Task::RELATED_LICENSE_ENTITLEMENT => LicenseEntitlement::query()
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->find($id),
            default => null,
        };
    }

    private function assertActorCanAccessEntity(User $actor, string $type, Model $entity): void
    {
        if ($actor->isGlobalAdmin()) {
            return;
        }

        $entityTenant = (int) ($entity->tenant_id ?? 0);
        if ($entityTenant !== (int) $actor->tenant_id) {
            throw new ModelNotFoundException('Related entity not found.');
        }

        if ($actor->isCompanyAdmin() || $actor->isFinanceAdmin()) {
            return;
        }

        if ($type === Task::RELATED_PAYOUT) {
            $this->payoutAccessScope->assertCanViewPayout($actor, $entity);

            return;
        }

        if ($type === Task::RELATED_LICENSE_ENTITLEMENT) {
            $holderId = (int) $entity->holder_organization_id;
            $visible = $this->accessScopeService->visibleChannelOrgIds($actor);
            if ($visible === [] || ! in_array($holderId, $visible, true)) {
                throw new ModelNotFoundException('Related entity not found.');
            }

            return;
        }

        if ($type === Task::RELATED_PAYMENT_RECORD) {
            $quote = Quote::query()->find($entity->quote_id);
            if (! $quote || ! $this->entityVisibleInScopedQuery($actor, Task::RELATED_QUOTE, (int) $quote->id)) {
                throw new ModelNotFoundException('Related entity not found.');
            }

            return;
        }

        if (! $this->entityVisibleInScopedQuery($actor, $type, (int) $entity->id)) {
            throw new ModelNotFoundException('Related entity not found.');
        }
    }

    private function entityVisibleInScopedQuery(User $actor, string $type, int $id): bool
    {
        $query = match ($type) {
            Task::RELATED_CONTACT => Contact::query()->whereKey($id),
            Task::RELATED_COMPANY => Company::query()->whereKey($id),
            Task::RELATED_DEAL => Deal::query()->whereKey($id),
            Task::RELATED_QUOTE => Quote::query()->whereKey($id),
            default => null,
        };

        if ($query === null) {
            return false;
        }

        if (! $actor->isGlobalAdmin()) {
            $query->where('tenant_id', $actor->tenant_id);
        }

        if ($actor->isPartnerPortalEligible()) {
            $this->accessScopeService->applyChannelOrganizationScope($query, $actor);

            return $query->exists();
        }

        if ($actor->currentRoleCode() === \App\Models\Role::CODE_USER) {
            $this->accessScopeService->applyOwnerTeamScope($query, $actor, 'assigned_user_id', 'created_by_user_id');

            return $query->exists();
        }

        return $query->exists();
    }

    private function buildSummary(string $type, Model $entity): ?string
    {
        return match ($type) {
            Task::RELATED_CONTACT => trim(($entity->first_name ?? '').' '.($entity->last_name ?? '')),
            Task::RELATED_COMPANY => $entity->name ?? $entity->legal_name ?? null,
            Task::RELATED_DEAL => $entity->name ?? null,
            Task::RELATED_QUOTE => $entity->quote_number ?? null,
            Task::RELATED_PAYMENT_RECORD => $entity->transaction_id ?? ('Payment #'.$entity->id),
            Task::RELATED_PAYOUT => $entity->payout_number ?? null,
            Task::RELATED_LICENSE_ENTITLEMENT => 'License #'.$entity->id,
            default => null,
        };
    }

    private function buildLabel(string $type, Model $entity): ?string
    {
        return match ($type) {
            Task::RELATED_CONTACT => 'Contact',
            Task::RELATED_COMPANY => 'Company',
            Task::RELATED_DEAL => 'Deal',
            Task::RELATED_QUOTE => 'Quote',
            Task::RELATED_PAYMENT_RECORD => 'Payment',
            Task::RELATED_PAYOUT => 'Payout',
            Task::RELATED_LICENSE_ENTITLEMENT => 'License',
            default => null,
        };
    }
}
