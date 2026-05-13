<?php

namespace App\Services\Prm;

use App\Models\Company;
use App\Models\PartnerLead;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\PartnerLeadRepository;
use App\Services\Contact\ContactManagementService;
use App\Support\PartnerScopeResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class PartnerLeadService
{
    public function __construct(
        private readonly PartnerLeadRepository $partnerLeadRepository,
        private readonly PartnerScopeResolver $partnerScopeResolver,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly ContactManagementService $contactManagementService,
    ) {}

    public function list(User $actor, int $perPage): LengthAwarePaginator
    {
        $orgId = $this->requirePartnerOrgId($actor);

        return $this->partnerLeadRepository->paginateForPartner($actor, $orgId, $perPage);
    }

    public function create(User $actor, array $payload, ?string $ip, ?string $ua): PartnerLead
    {
        $orgId = $this->requirePartnerOrgId($actor);
        $lead = $this->partnerLeadRepository->create([
            'tenant_id' => (int) $actor->tenant_id,
            'partner_organization_id' => $orgId,
            'title' => $payload['title'],
            'contact_email' => $payload['contact_email'] ?? null,
            'contact_first_name' => $payload['contact_first_name'] ?? null,
            'contact_last_name' => $payload['contact_last_name'] ?? null,
            'company_name' => $payload['company_name'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'description' => $payload['description'] ?? null,
            'status' => $payload['status'] ?? PartnerLead::STATUS_NEW,
            'approval_status' => $payload['approval_status'] ?? null,
            'assigned_user_id' => $payload['assigned_user_id'] ?? null,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
            'metadata' => $payload['metadata'] ?? null,
        ]);
        $mapped = $this->mapLeadToCanonicalCrm($actor, $lead, $payload);
        if ($mapped !== []) {
            $lead = $this->partnerLeadRepository->update($lead, ['metadata' => array_merge((array) ($lead->metadata ?? []), $mapped)]);
        }
        $this->audit($actor, 'prm.lead.created', $lead->id, null, $lead->toArray(), $ip, $ua);

        return $lead;
    }

    /**
     * Deprecated compatibility mapping:
     * PRM lead registration is persisted, and additionally mapped into canonical CRM entities.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mapLeadToCanonicalCrm(User $actor, PartnerLead $lead, array $payload): array
    {
        $mapping = [
            '_deprecated_mapping' => 'crm.contacts_companies',
            'source_partner_lead_id' => $lead->id,
        ];

        $companyId = null;
        $companyName = trim((string) ($payload['company_name'] ?? ''));
        if ($companyName !== '') {
            try {
                $company = $this->contactManagementService->createCompany($actor, [
                    'name' => $companyName,
                    'email' => $payload['contact_email'] ?? null,
                    'phone' => $payload['phone'] ?? null,
                    'assigned_user_id' => $payload['assigned_user_id'] ?? $actor->id,
                    'description' => $payload['description'] ?? null,
                    'status' => Company::STATUS_ACTIVE,
                ]);
                $companyId = $company->id;
                $mapping['mapped_company_id'] = $companyId;
            } catch (\Throwable) {
                // Keep endpoint backward-compatible even if canonical shadow mapping fails.
            }
        }

        $firstName = trim((string) ($payload['contact_first_name'] ?? ''));
        $lastName = trim((string) ($payload['contact_last_name'] ?? ''));
        if ($firstName === '' && $lastName === '') {
            $firstName = trim((string) ($payload['title'] ?? 'Lead'));
        }

        try {
            $contact = $this->contactManagementService->createContact($actor, Arr::whereNotNull([
                'first_name' => $firstName !== '' ? $firstName : 'Lead',
                'last_name' => $lastName !== '' ? $lastName : null,
                'email' => $payload['contact_email'] ?? null,
                'phone' => $payload['phone'] ?? null,
                'company_id' => $companyId,
                'assigned_user_id' => $payload['assigned_user_id'] ?? $actor->id,
                'meta' => [
                    'source' => 'prm_partner_lead',
                    'partner_lead_id' => $lead->id,
                ],
            ]));
            $mapping['mapped_contact_id'] = $contact->id;
        } catch (\Throwable) {
            // Non-blocking mapping by design in compatibility phase.
        }

        return $mapping;
    }

    public function update(User $actor, int $leadId, array $payload, ?string $ip, ?string $ua): PartnerLead
    {
        $orgId = $this->requirePartnerOrgId($actor);
        $lead = $this->partnerLeadRepository->findByIdForPartner($leadId, $orgId);
        if (! $lead) {
            throw new ModelNotFoundException('Lead not found.');
        }
        $before = $lead->toArray();
        $updated = $this->partnerLeadRepository->update($lead, array_merge(
            array_intersect_key($payload, array_flip([
                'title', 'contact_email', 'contact_first_name', 'contact_last_name', 'company_name',
                'phone', 'description', 'status', 'approval_status', 'assigned_user_id', 'metadata',
            ])),
            ['updated_by_user_id' => $actor->id]
        ));
        $this->audit($actor, 'prm.lead.updated', $updated->id, $before, $updated->toArray(), $ip, $ua);

        return $updated;
    }

    private function requirePartnerOrgId(User $actor): int
    {
        $ids = $this->partnerScopeResolver->visibleChannelOrganizationIds($actor);
        $primary = (int) ($actor->primaryOrganizationId() ?? 0);
        if ($primary <= 0 || ! in_array($primary, $ids, true)) {
            throw ValidationException::withMessages([
                'organization' => ['Invalid channel context.'],
            ]);
        }

        return $primary;
    }

    private function audit(User $actor, string $action, int $entityId, ?array $before, ?array $after, ?string $ip, ?string $ua): void
    {
        $this->auditLogRepository->create([
            'tenant_id' => (int) $actor->tenant_id,
            'user_id' => $actor->id,
            'module' => 'prm',
            'action' => $action,
            'entity_type' => 'partner_lead',
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $ip,
            'user_agent' => $ua,
        ]);
    }
}
