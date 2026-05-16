<?php

namespace App\Services\Prm;

use App\Models\OrganizationPayoutAccount;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Services\Auth\AccessScopeService;
use App\Services\Payment\PaymentSecretEncrypter;
use App\Support\Prm\PayoutAccessScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class OrganizationPayoutAccountService
{
    public function __construct(
        private readonly PaymentSecretEncrypter $encrypter,
        private readonly PayoutAccessScope $accessScope,
        private readonly AccessScopeService $accessScopeService,
        private readonly AuditLogRepository $auditLogRepository,
    ) {}

    public function listForActor(User $actor, ?int $organizationId, int $perPage): LengthAwarePaginator
    {
        $q = OrganizationPayoutAccount::query()->orderByDesc('is_primary')->orderByDesc('id');

        if (! $actor->isGlobalAdmin()) {
            $q->where('tenant_id', $actor->tenant_id);
        }

        if ($organizationId) {
            $this->assertOrgAccess($actor, $organizationId);
            $q->where('organization_id', $organizationId);
        } elseif (! $this->accessScope->canManageFinance($actor) && ! $actor->isGlobalAdmin()) {
            $orgIds = $this->accessScopeService->visibleChannelOrgIds($actor);
            $q->whereIn('organization_id', $orgIds);
        }

        return $q->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data, ?string $ip = null, ?string $ua = null): OrganizationPayoutAccount
    {
        $orgId = (int) $data['organization_id'];
        $this->assertOrgAccess($actor, $orgId);

        if ($data['is_primary'] ?? false) {
            OrganizationPayoutAccount::query()
                ->where('organization_id', $orgId)
                ->update(['is_primary' => false]);
        }

        $account = OrganizationPayoutAccount::query()->create([
            'tenant_id' => $actor->tenant_id,
            'organization_id' => $orgId,
            'account_holder_name' => (string) $data['account_holder_name'],
            'bank_name' => $data['bank_name'] ?? null,
            'branch_name' => $data['branch_name'] ?? null,
            'account_number_encrypted' => $this->encrypter->encrypt((string) $data['account_number']),
            'ifsc_code' => $data['ifsc_code'] ?? null,
            'swift_code' => $data['swift_code'] ?? null,
            'currency_code' => (string) ($data['currency_code'] ?? 'ZAR'),
            'account_type' => (string) ($data['account_type'] ?? 'current'),
            'is_primary' => (bool) ($data['is_primary'] ?? false),
            'verification_status' => OrganizationPayoutAccount::VERIFICATION_PENDING,
        ]);

        $this->audit($actor, $account, 'prm.payout_account.create', $ip, $ua);

        return $account;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, int $accountId, array $data, ?string $ip = null, ?string $ua = null): OrganizationPayoutAccount
    {
        $account = $this->findForActor($actor, $accountId);
        $before = $account->toArray();

        $updates = collect($data)->only([
            'account_holder_name', 'bank_name', 'branch_name', 'ifsc_code', 'swift_code',
            'currency_code', 'account_type', 'is_primary',
        ])->filter(fn ($v) => $v !== null)->all();

        if (! empty($data['account_number'])) {
            $updates['account_number_encrypted'] = $this->encrypter->encrypt((string) $data['account_number']);
        }

        if (($data['is_primary'] ?? false) === true) {
            OrganizationPayoutAccount::query()
                ->where('organization_id', $account->organization_id)
                ->where('id', '!=', $account->id)
                ->update(['is_primary' => false]);
        }

        $account->update($updates);
        $this->audit($actor, $account->refresh(), 'prm.payout_account.update', $ip, $ua, $before);

        return $account;
    }

    public function verify(User $actor, int $accountId, ?string $ip = null, ?string $ua = null): OrganizationPayoutAccount
    {
        $this->accessScope->assertCanManageFinance($actor);
        $account = $this->findForActor($actor, $accountId);
        $account->update([
            'verification_status' => OrganizationPayoutAccount::VERIFICATION_VERIFIED,
            'verified_at' => now(),
        ]);
        $this->audit($actor, $account, 'prm.payout_account.verify', $ip, $ua);

        return $account;
    }

    public function findForActor(User $actor, int $accountId): OrganizationPayoutAccount
    {
        $account = OrganizationPayoutAccount::query()->whereKey($accountId)->first();
        if (! $account) {
            throw ValidationException::withMessages(['account' => ['Payout account not found.']]);
        }

        $this->assertOrgAccess($actor, (int) $account->organization_id);

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArrayForActor(User $actor, OrganizationPayoutAccount $account, bool $reveal = false): array
    {
        $canReveal = $reveal && ($this->accessScope->canManageFinance($actor) || $this->orgInScope($actor, (int) $account->organization_id));

        return [
            'id' => $account->id,
            'organization_id' => $account->organization_id,
            'account_holder_name' => $account->account_holder_name,
            'bank_name' => $account->bank_name,
            'branch_name' => $account->branch_name,
            'account_number_masked' => $canReveal
                ? $this->maskAccount($this->encrypter->decrypt($account->account_number_encrypted))
                : '****',
            'ifsc_code' => $account->ifsc_code,
            'swift_code' => $account->swift_code,
            'currency_code' => $account->currency_code,
            'account_type' => $account->account_type,
            'is_primary' => $account->is_primary,
            'verification_status' => $account->verification_status,
            'verified_at' => $account->verified_at?->toIso8601String(),
        ];
    }

    private function maskAccount(?string $number): string
    {
        if (! $number || strlen($number) < 4) {
            return '****';
        }

        return str_repeat('*', max(0, strlen($number) - 4)).substr($number, -4);
    }

    private function assertOrgAccess(User $actor, int $organizationId): void
    {
        if ($this->accessScope->canManageFinance($actor) || $actor->isGlobalAdmin()) {
            return;
        }

        if (! $this->orgInScope($actor, $organizationId)) {
            throw ValidationException::withMessages(['organization' => ['Not allowed to manage payout accounts for this organization.']]);
        }

        if (! $actor->isPartnerAdmin() && $actor->currentRoleCode() !== \App\Models\Role::CODE_RESELLER_ADMIN) {
            throw ValidationException::withMessages(['organization' => ['Not allowed.']]);
        }
    }

    private function orgInScope(User $actor, int $organizationId): bool
    {
        return in_array($organizationId, $this->accessScopeService->visibleChannelOrgIds($actor), true);
    }

    private function audit(User $actor, OrganizationPayoutAccount $account, string $action, ?string $ip, ?string $ua, ?array $before = null): void
    {
        $this->auditLogRepository->create([
            'tenant_id' => $account->tenant_id,
            'user_id' => $actor->id,
            'module' => 'prm.payout',
            'action' => $action,
            'entity_type' => 'organization_payout_account',
            'entity_id' => $account->id,
            'before' => $before,
            'after' => $account->toArray(),
            'ip_address' => $ip,
            'user_agent' => $ua,
        ]);
    }
}
