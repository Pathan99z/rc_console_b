<?php

namespace App\Services\Prm;

use App\Models\PayoutDispute;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Support\Prm\PayoutAccessScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class PayoutDisputeService
{
    public function __construct(
        private readonly PayoutAccessScope $accessScope,
        private readonly PayoutGenerationService $generationService,
        private readonly AuditLogRepository $auditLogRepository,
    ) {}

    public function create(User $actor, array $data, ?string $ip = null, ?string $ua = null): PayoutDispute
    {
        $payout = $this->generationService->findForActor($actor, (int) $data['payout_id']);

        $dispute = PayoutDispute::query()->create([
            'tenant_id' => $payout->tenant_id,
            'payout_id' => $payout->id,
            'raised_by_user_id' => $actor->id,
            'status' => PayoutDispute::STATUS_OPEN,
            'description' => (string) $data['description'],
        ]);

        $this->auditLogRepository->create([
            'tenant_id' => $dispute->tenant_id,
            'user_id' => $actor->id,
            'module' => 'prm.payout',
            'action' => 'prm.payout.dispute.create',
            'entity_type' => 'payout_dispute',
            'entity_id' => $dispute->id,
            'after' => $dispute->toArray(),
            'ip_address' => $ip,
            'user_agent' => $ua,
        ]);

        return $dispute;
    }

    public function resolve(User $actor, int $disputeId, string $resolution, ?string $ip = null, ?string $ua = null): PayoutDispute
    {
        $dispute = $this->findDispute($actor, $disputeId);
        $this->accessScope->assertCanManageFinance($actor);

        $dispute->update([
            'status' => PayoutDispute::STATUS_RESOLVED,
            'resolution' => $resolution,
            'resolved_by_user_id' => $actor->id,
            'resolved_at' => now(),
        ]);

        $this->audit($actor, 'prm.payout.dispute.resolve', $dispute, $ip, $ua);

        return $dispute->refresh();
    }

    public function reject(User $actor, int $disputeId, string $resolution, ?string $ip = null, ?string $ua = null): PayoutDispute
    {
        $dispute = $this->findDispute($actor, $disputeId);
        $this->accessScope->assertCanManageFinance($actor);

        $dispute->update([
            'status' => PayoutDispute::STATUS_REJECTED,
            'resolution' => $resolution,
            'resolved_by_user_id' => $actor->id,
            'resolved_at' => now(),
        ]);

        $this->audit($actor, 'prm.payout.dispute.reject', $dispute, $ip, $ua);

        return $dispute->refresh();
    }

    public function listForActor(User $actor, int $perPage): LengthAwarePaginator
    {
        $q = PayoutDispute::query()->with('payout')->orderByDesc('id');

        if (! $actor->isGlobalAdmin()) {
            $q->where('tenant_id', $actor->tenant_id);
        }

        if (! $this->accessScope->canManageFinance($actor) && ! $actor->isGlobalAdmin()) {
            $orgIds = $this->accessScope->visibleBeneficiaryOrgIds($actor);
            $q->whereHas('payout', fn ($p) => $p->whereIn('beneficiary_organization_id', $orgIds));
        }

        return $q->paginate($perPage);
    }

    private function findDispute(User $actor, int $disputeId): PayoutDispute
    {
        $dispute = PayoutDispute::query()->whereKey($disputeId)->first();
        if (! $dispute) {
            throw ValidationException::withMessages(['dispute' => ['Dispute not found.']]);
        }

        $this->generationService->findForActor($actor, (int) $dispute->payout_id);

        return $dispute;
    }

    private function audit(User $actor, string $action, PayoutDispute $dispute, ?string $ip, ?string $ua): void
    {
        $this->auditLogRepository->create([
            'tenant_id' => $dispute->tenant_id,
            'user_id' => $actor->id,
            'module' => 'prm.payout',
            'action' => $action,
            'entity_type' => 'payout_dispute',
            'entity_id' => $dispute->id,
            'after' => $dispute->toArray(),
            'ip_address' => $ip,
            'user_agent' => $ua,
        ]);
    }
}
