<?php

namespace App\Services\Deal;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Repositories\DealHistoryRepository;
use App\Repositories\DealRepository;
use App\Repositories\PipelineRepository;
use App\Repositories\PipelineStageRepository;
use App\Support\DomainConstants;
use App\Support\Channel\ChannelContext;
use App\Support\PartnerScopeResolver;
use App\Events\Notifications\DealAssigned;
use App\Events\Notifications\DealLost;
use App\Events\Notifications\DealOwnerChanged;
use App\Events\Notifications\DealStageChanged;
use App\Events\Notifications\DealWon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DealManagementService
{
    public function __construct(
        private readonly PipelineRepository $pipelineRepository,
        private readonly PipelineStageRepository $pipelineStageRepository,
        private readonly DealRepository $dealRepository,
        private readonly DealHistoryRepository $dealHistoryRepository,
        private readonly PartnerScopeResolver $partnerScopeResolver,
        private readonly ChannelContext $channelContext,
    ) {}

    public function listPipelines(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $tenantId = $actor->isGlobalAdmin() ? ($filters['tenant_id'] ?? null) : $actor->tenant_id;
        $key = $this->buildCacheKey('pipelines', $tenantId, $filters, $perPage);

        return Cache::remember(
            $key,
            now()->addMinutes(15),
            fn () => $this->pipelineRepository->paginateFiltered($actor, $filters, $perPage)
        );
    }

    public function createPipeline(User $actor, array $payload): Pipeline
    {
        $tenantId = $this->resolveTenantId($actor, $payload);
        $pipeline = $this->pipelineRepository->create([
            'tenant_id' => $tenantId,
            'created_by_user_id' => $actor->id,
            'name' => $payload['name'],
            'status' => (int) ($payload['status'] ?? Pipeline::STATUS_ACTIVE),
        ]);
        $this->bumpVersion('pipelines', $tenantId);

        return $this->pipelineRepository->findById($pipeline->id) ?? $pipeline;
    }

    public function updatePipeline(User $actor, int $pipelineId, array $payload): Pipeline
    {
        $pipeline = $this->mustGetPipeline($pipelineId);
        $this->ensureTenantAccess($actor, (int) $pipeline->tenant_id, DomainConstants::MSG_PIPELINE_NOT_FOUND);
        $updated = $this->pipelineRepository->update($pipeline, $payload);
        $this->bumpVersion('pipelines', (int) $pipeline->tenant_id);
        $this->bumpVersion('deals', (int) $pipeline->tenant_id);

        return $this->pipelineRepository->findById($updated->id) ?? $updated;
    }

    public function deletePipeline(User $actor, int $pipelineId): void
    {
        $pipeline = $this->mustGetPipeline($pipelineId);
        $this->ensureTenantAccess($actor, (int) $pipeline->tenant_id, DomainConstants::MSG_PIPELINE_NOT_FOUND);
        $pipeline->delete();
        $this->bumpVersion('pipelines', (int) $pipeline->tenant_id);
        $this->bumpVersion('deals', (int) $pipeline->tenant_id);
    }

    public function listStages(User $actor, int $pipelineId): Collection
    {
        $pipeline = $this->mustGetPipeline($pipelineId);
        $this->ensureTenantAccess($actor, (int) $pipeline->tenant_id, DomainConstants::MSG_PIPELINE_NOT_FOUND);

        return $this->pipelineStageRepository->listByPipeline($pipelineId);
    }

    public function createStage(User $actor, int $pipelineId, array $payload): PipelineStage
    {
        $pipeline = $this->mustGetPipeline($pipelineId);
        $this->ensureTenantAccess($actor, (int) $pipeline->tenant_id, DomainConstants::MSG_PIPELINE_NOT_FOUND);
        $stage = $this->pipelineStageRepository->create([
            'tenant_id' => $pipeline->tenant_id,
            'pipeline_id' => $pipeline->id,
            'name' => $payload['name'],
            'stage_order' => (int) $payload['stage_order'],
            'status' => (int) ($payload['status'] ?? PipelineStage::STATUS_ACTIVE),
        ]);
        $this->bumpVersion('pipelines', (int) $pipeline->tenant_id);
        $this->bumpVersion('deals', (int) $pipeline->tenant_id);

        return $stage;
    }

    public function updateStage(User $actor, int $pipelineId, int $stageId, array $payload): PipelineStage
    {
        $pipeline = $this->mustGetPipeline($pipelineId);
        $this->ensureTenantAccess($actor, (int) $pipeline->tenant_id, DomainConstants::MSG_PIPELINE_NOT_FOUND);
        $stage = $this->mustGetStage($stageId);
        $this->ensureStageInPipeline($stage, $pipeline);
        $updated = $this->pipelineStageRepository->update($stage, $payload);
        $this->bumpVersion('pipelines', (int) $pipeline->tenant_id);
        $this->bumpVersion('deals', (int) $pipeline->tenant_id);

        return $updated;
    }

    public function deleteStage(User $actor, int $pipelineId, int $stageId): void
    {
        $pipeline = $this->mustGetPipeline($pipelineId);
        $this->ensureTenantAccess($actor, (int) $pipeline->tenant_id, DomainConstants::MSG_PIPELINE_NOT_FOUND);
        $stage = $this->mustGetStage($stageId);
        $this->ensureStageInPipeline($stage, $pipeline);
        $stage->delete();
        $this->bumpVersion('pipelines', (int) $pipeline->tenant_id);
        $this->bumpVersion('deals', (int) $pipeline->tenant_id);
    }

    public function listDeals(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $tenantId = $actor->isGlobalAdmin() ? ($filters['tenant_id'] ?? null) : $actor->tenant_id;
        $key = $this->buildCacheKey('deals', $tenantId, $filters, $perPage);

        return Cache::remember($key, now()->addMinutes(10), fn () => $this->dealRepository->paginateFiltered($actor, $filters, $perPage));
    }

    public function createDeal(User $actor, array $payload): Deal
    {
        $tenantId = $this->resolveTenantId($actor, $payload);
        $this->validateDealRelations($actor, $tenantId, $payload);

        $partnerOrgId = $payload['partner_organization_id'] ?? null;
        if ($partnerOrgId === null && $actor->isPartnerPortalEligible()) {
            $partnerOrgId = $actor->primaryOrganizationId();
        }

        $channelOrgId = $payload['channel_organization_id'] ?? $partnerOrgId;
        if ($channelOrgId === null && $actor->isPartnerPortalEligible()) {
            $channelOrgId = $actor->primaryOrganizationId();
        }

        $deal = $this->dealRepository->create([
            'tenant_id' => $tenantId,
            'channel_organization_id' => $channelOrgId,
            'partner_organization_id' => $partnerOrgId,
            'partner_registered_by_user_id' => $partnerOrgId ? $actor->id : null,
            'partner_opportunity_fingerprint' => $payload['partner_opportunity_fingerprint'] ?? null,
            'contact_id' => (int) $payload['contact_id'],
            'company_id' => $payload['company_id'] ?? null,
            'owner_user_id' => (int) $payload['owner_user_id'],
            'pipeline_id' => (int) $payload['pipeline_id'],
            'pipeline_stage_id' => (int) $payload['pipeline_stage_id'],
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
            'name' => $payload['name'],
            'estimated_value' => $payload['estimated_value'] ?? null,
            'currency_code' => isset($payload['currency_code']) ? strtoupper((string) $payload['currency_code']) : null,
            'probability' => isset($payload['probability']) ? (int) $payload['probability'] : null,
            'expected_close_date' => $payload['expected_close_date'] ?? null,
            'status' => Deal::statusCodeFromString($payload['status'] ?? 'open'),
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->recordHistory((int) $tenantId, $deal->id, $actor->id, 'created', null, $deal->statusLabel(), 'Deal created');
        Log::info(DomainConstants::LOG_DEAL_CREATED, ['tenant_id' => $tenantId, 'deal_id' => $deal->id]);
        $this->bumpVersion('deals', (int) $tenantId);

        $fresh = $this->mustGetDeal($deal->id);
        if ((int) $fresh->owner_user_id !== (int) $actor->id) {
            event(new DealAssigned($fresh->id, $actor->id));
        }

        return $fresh;
    }

    public function getDeal(User $actor, int $dealId): Deal
    {
        $deal = $this->mustGetDeal($dealId);
        if (! $this->hasDealVisibility($actor, $deal)) {
            throw new ModelNotFoundException(DomainConstants::MSG_DEAL_NOT_FOUND);
        }

        return $deal;
    }

    public function updateDeal(User $actor, int $dealId, array $payload): Deal
    {
        $deal = $this->getDeal($actor, $dealId);
        $this->ensureDealMutationAccess($actor, $deal);
        $tenantId = (int) $deal->tenant_id;
        $previousOwnerUserId = (int) $deal->owner_user_id;
        $this->validateDealRelations($actor, $tenantId, $payload, $deal);
        $updatePayload = array_merge($payload, ['updated_by_user_id' => $actor->id]);
        if (isset($payload['currency_code'])) {
            $updatePayload['currency_code'] = strtoupper((string) $payload['currency_code']);
        }
        if (isset($payload['probability'])) {
            $updatePayload['probability'] = (int) $payload['probability'];
        }

        $updated = $this->dealRepository->update($deal, $updatePayload);
        if (isset($payload['owner_user_id']) && (int) $payload['owner_user_id'] !== (int) $deal->owner_user_id) {
            $this->recordHistory($tenantId, $deal->id, $actor->id, 'owner_changed', (string) $deal->owner_user_id, (string) $payload['owner_user_id'], null);
        }

        Log::info(DomainConstants::LOG_DEAL_UPDATED, ['tenant_id' => $tenantId, 'deal_id' => $deal->id]);
        $this->bumpVersion('deals', $tenantId);

        $freshDeal = $this->mustGetDeal($updated->id);
        if (isset($payload['owner_user_id']) && (int) $payload['owner_user_id'] !== $previousOwnerUserId) {
            event(new DealOwnerChanged($freshDeal->id, $previousOwnerUserId, (int) $payload['owner_user_id'], $actor->id));
        }

        return $freshDeal;
    }

    public function deleteDeal(User $actor, int $dealId): void
    {
        $deal = $this->getDeal($actor, $dealId);
        $this->ensureDealMutationAccess($actor, $deal);
        $this->dealRepository->delete($deal);
        $this->bumpVersion('deals', (int) $deal->tenant_id);
    }

    public function moveStage(User $actor, int $dealId, int $stageId, ?string $notes): Deal
    {
        $deal = $this->getDeal($actor, $dealId);
        $this->ensureDealMutationAccess($actor, $deal);
        $stage = $this->mustGetStage($stageId);
        if ((int) $stage->pipeline_id !== (int) $deal->pipeline_id || (int) $stage->tenant_id !== (int) $deal->tenant_id) {
            throw new ModelNotFoundException(DomainConstants::MSG_PIPELINE_STAGE_NOT_FOUND);
        }

        $previousStageId = (string) $deal->pipeline_stage_id;
        $updated = $this->dealRepository->update($deal, [
            'pipeline_stage_id' => $stage->id,
            'updated_by_user_id' => $actor->id,
        ]);
        $this->recordHistory((int) $deal->tenant_id, $deal->id, $actor->id, 'stage_moved', $previousStageId, (string) $stage->id, $notes);
        Log::info(DomainConstants::LOG_DEAL_STAGE_MOVED, ['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id]);
        $this->bumpVersion('deals', (int) $deal->tenant_id);

        $freshDeal = $this->mustGetDeal($updated->id);
        event(new DealStageChanged($freshDeal->id, (int) $stage->id, (string) $stage->name));

        return $freshDeal;
    }

    public function updateStatus(User $actor, int $dealId, string $status, ?string $notes): Deal
    {
        $deal = $this->getDeal($actor, $dealId);
        $this->ensureDealMutationAccess($actor, $deal);
        if (! in_array($status, ['open', 'won', 'lost'], true)) {
            throw ValidationException::withMessages([
                'status' => [DomainConstants::MSG_DEAL_STATUS_INVALID],
            ]);
        }

        $fromStatus = $deal->statusLabel();
        $updated = $this->dealRepository->update($deal, [
            'status' => Deal::statusCodeFromString($status),
            'updated_by_user_id' => $actor->id,
        ]);
        $this->recordHistory((int) $deal->tenant_id, $deal->id, $actor->id, 'status_changed', $fromStatus, $status, $notes);
        Log::info(DomainConstants::LOG_DEAL_STATUS_CHANGED, ['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id]);
        $this->bumpVersion('deals', (int) $deal->tenant_id);

        $freshDeal = $this->mustGetDeal($updated->id);
        if ($status === 'won') {
            event(new DealWon($freshDeal->id));
        }
        if ($status === 'lost') {
            event(new DealLost($freshDeal->id, $actor->id));
        }

        return $freshDeal;
    }

    private function validateDealRelations(User $actor, int $tenantId, array $payload, ?Deal $currentDeal = null): void
    {
        $contactId = isset($payload['contact_id']) ? (int) $payload['contact_id'] : $currentDeal?->contact_id;
        if (! $contactId || ! Contact::query()->where('id', $contactId)->where('tenant_id', $tenantId)->exists()) {
            throw ValidationException::withMessages(['contact_id' => [DomainConstants::MSG_DEAL_CONTACT_REQUIRED]]);
        }

        if (array_key_exists('company_id', $payload) && $payload['company_id'] !== null) {
            $companyId = (int) $payload['company_id'];
            if (! Company::query()->where('id', $companyId)->where('tenant_id', $tenantId)->exists()) {
                throw ValidationException::withMessages(['company_id' => [DomainConstants::MSG_COMPANY_NOT_FOUND]]);
            }
        }

        $ownerId = isset($payload['owner_user_id']) ? (int) $payload['owner_user_id'] : $currentDeal?->owner_user_id;
        if (! $ownerId || ! User::query()->where('id', $ownerId)->where('tenant_id', $tenantId)->exists()) {
            throw ValidationException::withMessages(['owner_user_id' => [DomainConstants::MSG_UNAUTHORIZED_SCOPE]]);
        }

        $pipelineId = isset($payload['pipeline_id']) ? (int) $payload['pipeline_id'] : $currentDeal?->pipeline_id;
        if (! $pipelineId || ! Pipeline::query()->where('id', $pipelineId)->where('tenant_id', $tenantId)->exists()) {
            throw ValidationException::withMessages(['pipeline_id' => [DomainConstants::MSG_PIPELINE_NOT_FOUND]]);
        }

        $stageId = isset($payload['pipeline_stage_id']) ? (int) $payload['pipeline_stage_id'] : $currentDeal?->pipeline_stage_id;
        if (! $stageId || ! PipelineStage::query()->where('id', $stageId)->where('tenant_id', $tenantId)->where('pipeline_id', $pipelineId)->exists()) {
            throw ValidationException::withMessages(['pipeline_stage_id' => [DomainConstants::MSG_PIPELINE_STAGE_NOT_FOUND]]);
        }

        $this->assertOptionalPartnerOrganization($actor, $tenantId, $payload, $currentDeal);
    }

    private function assertOptionalPartnerOrganization(User $actor, int $tenantId, array $payload, ?Deal $currentDeal = null): void
    {
        $partnerOrgId = isset($payload['partner_organization_id'])
            ? (int) $payload['partner_organization_id']
            : (int) ($currentDeal?->partner_organization_id ?? 0);
        if ($partnerOrgId <= 0) {
            return;
        }

        $org = Organization::query()->where('id', $partnerOrgId)->where('tenant_id', $tenantId)->first();
        if (! $org) {
            throw ValidationException::withMessages([
                'partner_organization_id' => ['Invalid partner organization for tenant.'],
            ]);
        }

        if ($actor->isPartnerPortalEligible()) {
            $allowed = $this->partnerScopeResolver->visibleChannelOrganizationIds($actor);
            if (! in_array($partnerOrgId, $allowed, true)) {
                throw ValidationException::withMessages([
                    'partner_organization_id' => [DomainConstants::MSG_UNAUTHORIZED_SCOPE],
                ]);
            }
        }
    }

    private function recordHistory(int $tenantId, int $dealId, int $userId, string $type, ?string $from, ?string $to, ?string $notes): void
    {
        $this->dealHistoryRepository->create([
            'tenant_id' => $tenantId,
            'deal_id' => $dealId,
            'user_id' => $userId,
            'type' => $type,
            'from_value' => $from,
            'to_value' => $to,
            'notes' => $notes,
        ]);
    }

    private function ensureDealMutationAccess(User $actor, Deal $deal): void
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return;
        }

        if (! $this->hasDealVisibility($actor, $deal)) {
            throw new ModelNotFoundException(DomainConstants::MSG_DEAL_NOT_FOUND);
        }
    }

    private function hasDealVisibility(User $actor, Deal $deal): bool
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return true;
        }

        if ((int) $deal->tenant_id !== (int) $actor->tenant_id) {
            return false;
        }

        if ((int) $deal->owner_user_id === (int) $actor->id) {
            return true;
        }

        if ((int) $actor->data_scope !== DomainConstants::DATA_SCOPE_TEAM || $actor->team_id === null) {
            return false;
        }

        $teamUserIds = User::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('team_id', $actor->team_id)
            ->pluck('id')
            ->all();

        if (in_array((int) $deal->owner_user_id, array_map('intval', $teamUserIds), true)) {
            return true;
        }

        $channelIds = $this->partnerScopeResolver->visibleChannelOrganizationIds($actor);
        if ($deal->partner_organization_id && in_array((int) $deal->partner_organization_id, $channelIds, true)) {
            return true;
        }

        return false;
    }

    private function mustGetDeal(int $dealId): Deal
    {
        $deal = $this->dealRepository->findById($dealId);
        if (! $deal) {
            throw new ModelNotFoundException(DomainConstants::MSG_DEAL_NOT_FOUND);
        }

        return $deal;
    }

    private function mustGetPipeline(int $pipelineId): Pipeline
    {
        $pipeline = $this->pipelineRepository->findById($pipelineId);
        if (! $pipeline) {
            throw new ModelNotFoundException(DomainConstants::MSG_PIPELINE_NOT_FOUND);
        }

        return $pipeline;
    }

    private function mustGetStage(int $stageId): PipelineStage
    {
        $stage = $this->pipelineStageRepository->findById($stageId);
        if (! $stage) {
            throw new ModelNotFoundException(DomainConstants::MSG_PIPELINE_STAGE_NOT_FOUND);
        }

        return $stage;
    }

    private function ensureStageInPipeline(PipelineStage $stage, Pipeline $pipeline): void
    {
        if ((int) $stage->pipeline_id !== (int) $pipeline->id || (int) $stage->tenant_id !== (int) $pipeline->tenant_id) {
            throw new ModelNotFoundException(DomainConstants::MSG_PIPELINE_STAGE_NOT_FOUND);
        }
    }

    private function ensureTenantAccess(User $actor, int $tenantId, string $message): void
    {
        if ($actor->isGlobalAdmin()) {
            return;
        }

        if ((int) $actor->tenant_id !== $tenantId) {
            throw new ModelNotFoundException($message);
        }
    }

    private function resolveTenantId(User $actor, array $payload): ?int
    {
        if (! $actor->isGlobalAdmin()) {
            return $actor->tenant_id;
        }

        if (! isset($payload['tenant_id'])) {
            throw ValidationException::withMessages([
                'tenant_id' => [DomainConstants::MSG_TENANT_REQUIRED],
            ]);
        }

        return (int) $payload['tenant_id'];
    }

    private function buildCacheKey(string $module, ?int $tenantId, array $filters, int $perPage): string
    {
        $version = Cache::get($this->versionKey($module, $tenantId), 1);

        return "{$module}:tenant:{$tenantId}:v:{$version}:p:{$perPage}:f:".md5(json_encode($filters));
    }

    private function bumpVersion(string $module, ?int $tenantId): void
    {
        Cache::add($this->versionKey($module, $tenantId), 1, now()->addDays(30));
        Cache::increment($this->versionKey($module, $tenantId));
    }

    private function versionKey(string $module, ?int $tenantId): string
    {
        return "{$module}:tenant:{$tenantId}:version";
    }
}
