<?php

namespace App\Services\Prm;

use App\Models\Organization;
use App\Models\PartnerProgram;
use App\Models\PartnerProgramEnrollment;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PartnerProgramService
{
    public function __construct(private readonly AuditLogRepository $auditLogRepository) {}

    public function listPrograms(User $actor, ?int $tenantIdForGlobalAdmin = null): Collection
    {
        if ($actor->isGlobalAdmin()) {
            if ($tenantIdForGlobalAdmin === null || $tenantIdForGlobalAdmin < 1) {
                throw ValidationException::withMessages([
                    'tenant_id' => ['tenant_id filter is required for global admin.'],
                ]);
            }

            return PartnerProgram::query()
                ->where('tenant_id', $tenantIdForGlobalAdmin)
                ->orderBy('tier_level')
                ->orderBy('code')
                ->orderBy('id')
                ->get();
        }

        $tenantId = (int) $actor->tenant_id;

        return PartnerProgram::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('tier_level')
            ->orderBy('code')
            ->orderBy('id')
            ->get();
    }

    public function enroll(User $actor, int $organizationId, int $programId, ?float $commissionPercent, ?string $ip, ?string $ua): PartnerProgramEnrollment
    {
        if (! $actor->isCompanyAdmin() && ! $actor->isGlobalAdmin()) {
            throw ValidationException::withMessages([
                'organization' => ['Only company administrators can enroll partners.'],
            ]);
        }

        $org = Organization::query()->whereKey($organizationId)->first();
        if (! $org || ((int) $org->tenant_id !== (int) $actor->tenant_id && ! $actor->isGlobalAdmin())) {
            throw ValidationException::withMessages(['organization_id' => ['Invalid organization.']]);
        }

        if (! in_array($org->type, [Organization::TYPE_PARTNER, Organization::TYPE_RESELLER], true)) {
            throw ValidationException::withMessages([
                'organization_id' => ['Program enrollment is only available for partner or direct reseller organizations.'],
            ]);
        }

        if ($org->type === Organization::TYPE_RESELLER
            && $org->channel_mode === Organization::CHANNEL_MODE_PARTNER_MANAGED) {
            throw ValidationException::withMessages([
                'organization_id' => ['Partner-managed resellers inherit commercial terms from the parent partner.'],
            ]);
        }

        $program = PartnerProgram::query()->whereKey($programId)->where('tenant_id', $org->tenant_id)->first();
        if (! $program) {
            throw ValidationException::withMessages(['partner_program_id' => ['Invalid program.']]);
        }

        if (! $program->isActive()) {
            throw ValidationException::withMessages([
                'partner_program_id' => ['This partner program is not active.'],
            ]);
        }

        $tierCode = (string) $program->code;

        $enrollment = PartnerProgramEnrollment::query()->updateOrCreate(
            [
                'tenant_id' => $org->tenant_id,
                'organization_id' => $org->id,
                'partner_program_id' => $program->id,
            ],
            [
                'tier_code' => $tierCode,
                'commission_percent' => $commissionPercent,
                'status' => PartnerProgramEnrollment::STATUS_ACTIVE,
                'created_by_user_id' => $actor->id,
            ]
        );

        $this->auditLogRepository->create([
            'tenant_id' => $org->tenant_id,
            'user_id' => $actor->id,
            'module' => 'prm',
            'action' => 'prm.program.enrolled',
            'entity_type' => 'partner_program_enrollment',
            'entity_id' => $enrollment->id,
            'before' => null,
            'after' => $enrollment->toArray(),
            'ip_address' => $ip,
            'user_agent' => $ua,
        ]);

        return $enrollment->load(['program', 'organization']);
    }

    public function listEnrollments(User $actor, int $organizationId): Collection
    {
        if (! $actor->isCompanyAdmin() && ! $actor->isGlobalAdmin()) {
            throw ValidationException::withMessages([
                'organization' => ['Not allowed.'],
            ]);
        }

        $org = Organization::query()->whereKey($organizationId)->first();
        if (! $org || (int) $org->tenant_id !== (int) $actor->tenant_id) {
            throw ValidationException::withMessages(['organization_id' => ['Invalid organization.']]);
        }

        return PartnerProgramEnrollment::query()
            ->with(['program', 'organization'])
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Active program enrollments for the authenticated channel user's primary organization (read-only).
     *
     * @return Collection<int, PartnerProgramEnrollment>
     */
    public function listActiveEnrollmentsForPartnerPrimaryOrg(User $actor): Collection
    {
        if (! $actor->isPartnerPortalEligible()) {
            throw ValidationException::withMessages([
                'organization' => ['Not allowed to list program enrollments.'],
            ]);
        }

        $organizationId = $actor->primaryOrganizationId();
        if ($organizationId === null || $organizationId <= 0) {
            throw ValidationException::withMessages([
                'organization' => ['No channel organization assignment found for this user.'],
            ]);
        }

        return PartnerProgramEnrollment::query()
            ->with(['program', 'organization'])
            ->where('organization_id', $organizationId)
            ->where('status', PartnerProgramEnrollment::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->get();
    }

    public function getProgramForManage(User $actor, int $programId, ?int $tenantIdForGlobalAdmin): PartnerProgram
    {
        $tenantId = $this->resolveProgramsTenantIdForActor($actor, $tenantIdForGlobalAdmin);
        $program = PartnerProgram::query()->whereKey($programId)->where('tenant_id', $tenantId)->first();
        if (! $program) {
            throw (new ModelNotFoundException)->setModel(PartnerProgram::class, [$programId]);
        }

        return $program;
    }

    public function createProgram(User $actor, array $payload, ?string $ip, ?string $ua): PartnerProgram
    {
        $tenantId = $this->resolveProgramsTenantIdForActor(
            $actor,
            $actor->isGlobalAdmin() ? (int) ($payload['tenant_id'] ?? 0) : null
        );

        $program = PartnerProgram::query()->create([
            'tenant_id' => $tenantId,
            'code' => (string) $payload['code'],
            'name' => (string) $payload['name'],
            'description' => $payload['description'] ?? null,
            'tier_level' => (int) $payload['tier_level'],
            'default_commission_percent' => (float) $payload['default_commission_percent'],
            'status' => (string) ($payload['status'] ?? PartnerProgram::STATUS_ACTIVE),
            'rules' => $payload['rules'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
            'is_template' => (bool) ($payload['is_template'] ?? false),
        ]);

        $this->auditProgram($actor, $program->tenant_id, 'prm.program.created', $program, null, $program->toArray(), [
            'ip_address' => $ip,
            'user_agent' => $ua,
        ]);

        return $program;
    }

    public function updateProgram(User $actor, int $programId, array $payload, ?int $tenantIdForGlobalAdmin, ?string $ip, ?string $ua): PartnerProgram
    {
        $program = $this->getProgramForManage($actor, $programId, $tenantIdForGlobalAdmin);
        $before = $program->toArray();

        if (isset($payload['code']) && (string) $payload['code'] !== $program->code) {
            $exists = PartnerProgram::query()
                ->where('tenant_id', $program->tenant_id)
                ->where('code', (string) $payload['code'])
                ->where('id', '<>', $program->id)
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages([
                    'code' => ['A program with this code already exists for the tenant.'],
                ]);
            }
        }

        $updates = [];
        foreach (['code', 'name', 'description', 'tier_level', 'default_commission_percent', 'rules', 'metadata', 'is_template'] as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }
            $updates[$field] = $payload[$field];
        }

        if ($updates !== []) {
            $program->fill($updates);
            $program->save();
        }

        $fresh = $program->refresh();
        $this->auditProgram($actor, $fresh->tenant_id, 'prm.program.updated', $fresh, $before, $fresh->toArray(), [
            'ip_address' => $ip,
            'user_agent' => $ua,
        ]);

        return $fresh;
    }

    public function updateProgramStatus(
        User $actor,
        int $programId,
        string $status,
        ?int $tenantIdForGlobalAdmin,
        ?string $ip,
        ?string $ua
    ): PartnerProgram {
        $program = $this->getProgramForManage($actor, $programId, $tenantIdForGlobalAdmin);
        $before = $program->toArray();

        if (! in_array($status, [PartnerProgram::STATUS_ACTIVE, PartnerProgram::STATUS_INACTIVE], true)) {
            throw ValidationException::withMessages(['status' => ['Invalid status.']]);
        }

        if ($program->status === $status) {
            return $program;
        }

        $program->update(['status' => $status]);
        $fresh = $program->refresh();
        $this->auditProgram($actor, $fresh->tenant_id, 'prm.program.status', $fresh, $before, $fresh->toArray(), [
            'ip_address' => $ip,
            'user_agent' => $ua,
        ]);

        return $fresh;
    }

    private function resolveProgramsTenantIdForActor(User $actor, ?int $tenantIdForGlobalAdmin): int
    {
        if ($actor->isGlobalAdmin()) {
            if ($tenantIdForGlobalAdmin === null || $tenantIdForGlobalAdmin < 1) {
                throw ValidationException::withMessages([
                    'tenant_id' => ['tenant_id filter is required for global admin.'],
                ]);
            }

            return $tenantIdForGlobalAdmin;
        }

        return (int) $actor->tenant_id;
    }

    private function auditProgram(
        User $actor,
        int $tenantId,
        string $action,
        PartnerProgram $program,
        ?array $before,
        ?array $after,
        array $requestContext,
    ): void {
        $this->auditLogRepository->create([
            'tenant_id' => $tenantId,
            'user_id' => $actor->id,
            'module' => 'prm',
            'action' => $action,
            'entity_type' => 'partner_program',
            'entity_id' => $program->id,
            'before' => $before,
            'after' => $after,
            'ip_address' => $requestContext['ip_address'] ?? null,
            'user_agent' => $requestContext['user_agent'] ?? null,
        ]);
    }
}
