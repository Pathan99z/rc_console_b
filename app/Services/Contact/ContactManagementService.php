<?php

namespace App\Services\Contact;

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Repositories\CompanyRepository;
use App\Repositories\ContactActivityRepository;
use App\Repositories\ContactRepository;
use App\Support\DomainConstants;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ContactManagementService
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly ContactRepository $contactRepository,
        private readonly ContactActivityRepository $activityRepository,
    ) {
    }

    public function listCompanies(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $tenantId = $actor->isGlobalAdmin() ? (isset($filters['tenant_id']) ? (int) $filters['tenant_id'] : null) : $actor->tenant_id;
        $key = $this->buildCacheKey('companies', $tenantId, $filters, $perPage);

        return Cache::remember($key, now()->addMinutes(10), fn () => $this->companyRepository->paginateFiltered($actor, $filters, $perPage));
    }

    public function createCompany(User $actor, array $payload): Company
    {
        $payload['tenant_id'] = $this->resolveTenantId($actor, $payload);
        $payload['created_by_user_id'] = $actor->id;
        $this->guardCompanyAssignee($payload['tenant_id'], $payload['assigned_user_id'] ?? null);
        $this->ensureUniqueCompanyEmail((int) $payload['tenant_id'], $payload['email'] ?? null);
        $company = $this->companyRepository->create($payload);
        $this->bumpVersion('companies', (int) $payload['tenant_id']);

        return $this->companyRepository->findById($company->id) ?? $company;
    }

    public function updateCompany(User $actor, int $companyId, array $payload): Company
    {
        $company = $this->companyRepository->findById($companyId);
        if (! $company || ! $this->canAccessCompany($actor, (int) $company->tenant_id)) {
            throw new ModelNotFoundException(DomainConstants::MSG_COMPANY_NOT_FOUND);
        }

        $this->guardCompanyAssignee((int) $company->tenant_id, $payload['assigned_user_id'] ?? null);
        $this->ensureUniqueCompanyEmail((int) $company->tenant_id, $payload['email'] ?? null, $company->id);
        $updated = $this->companyRepository->update($company, $payload);
        $this->bumpVersion('companies', (int) $company->tenant_id);

        return $this->companyRepository->findById($updated->id) ?? $updated;
    }

    public function deleteCompany(User $actor, int $companyId): void
    {
        $company = $this->companyRepository->findById($companyId);
        if (! $company || ! $this->canAccessCompany($actor, (int) $company->tenant_id)) {
            throw new ModelNotFoundException(DomainConstants::MSG_COMPANY_NOT_FOUND);
        }

        $company->delete();
        $this->bumpVersion('companies', (int) $company->tenant_id);
    }

    public function listContacts(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $tenantId = $actor->isGlobalAdmin() ? (isset($filters['tenant_id']) ? (int) $filters['tenant_id'] : null) : $actor->tenant_id;
        $key = $this->buildCacheKey('contacts', $tenantId, $filters, $perPage);

        return Cache::remember($key, now()->addMinutes(5), fn () => $this->contactRepository->paginateFiltered($actor, $filters, $perPage));
    }

    public function createContact(User $actor, array $payload): Contact
    {
        $payload['tenant_id'] = $this->resolveTenantId($actor, $payload);
        $payload['created_by_user_id'] = $actor->id;
        $payload['updated_by_user_id'] = $actor->id;
        $payload['lifecycle_stage'] = (int) ($payload['lifecycle_stage'] ?? Contact::STAGE_LEAD);
        $this->ensureUniqueContactEmail((int) $payload['tenant_id'], $payload['email'] ?? null);
        $this->guardTenantForeigns($actor, $payload);

        $contact = $this->contactRepository->create($payload);
        $this->bumpVersion('contacts', (int) $payload['tenant_id']);

        return $this->mustGetContact($contact->id);
    }

    public function getContact(User $actor, int $id): Contact
    {
        $contact = $this->mustGetContact($id);
        $visible = $this->contactRepository->queryForExport($actor, [])->where('contacts.id', $id)->exists();
        if (! $visible) {
            throw new ModelNotFoundException(DomainConstants::MSG_CONTACT_NOT_FOUND);
        }

        return $contact;
    }

    public function updateContact(User $actor, int $id, array $payload): Contact
    {
        $contact = $this->getContact($actor, $id);
        $this->guardTenantForeigns($actor, $payload);
        $this->ensureUniqueContactEmail((int) $contact->tenant_id, $payload['email'] ?? null, $contact->id);
        $payload['updated_by_user_id'] = $actor->id;

        $updated = $this->contactRepository->update($contact, $payload);
        $this->bumpVersion('contacts', (int) $contact->tenant_id);

        return $this->mustGetContact($updated->id);
    }

    public function deleteContact(User $actor, int $id): void
    {
        $contact = $this->getContact($actor, $id);
        $this->contactRepository->delete($contact);
        $this->bumpVersion('contacts', (int) $contact->tenant_id);
    }

    public function addActivity(User $actor, int $contactId, array $payload): Contact
    {
        $contact = $this->getContact($actor, $contactId);

        $this->activityRepository->create([
            'tenant_id' => $contact->tenant_id,
            'contact_id' => $contact->id,
            'user_id' => $actor->id,
            'type' => (string) ($payload['type'] ?? 'note'),
            'note' => (string) $payload['note'],
            'occurred_at' => $payload['occurred_at'] ?? now(),
        ]);

        $this->bumpVersion('contacts', (int) $contact->tenant_id);

        return $this->mustGetContact($contact->id);
    }

    public function attachCompany(User $actor, int $contactId, int $companyId): Contact
    {
        return $this->updateContact($actor, $contactId, ['company_id' => $companyId]);
    }

    public function detachCompany(User $actor, int $contactId): Contact
    {
        return $this->updateContact($actor, $contactId, ['company_id' => null]);
    }

    public function importContacts(User $actor, UploadedFile $file, ?int $tenantId = null): array
    {
        $tenantId = $this->resolveTenantId($actor, ['tenant_id' => $tenantId]);
        Log::info(DomainConstants::LOG_CONTACT_IMPORT_STARTED, ['tenant_id' => $tenantId, 'user_id' => $actor->id]);

        $path = Storage::disk(config('filesystems.default'))->putFile('contact-imports', $file);
        $content = Storage::disk(config('filesystems.default'))->get($path);
        $rows = preg_split("/\r\n|\n|\r/", trim($content)) ?: [];
        if ($rows === []) {
            return ['created' => 0, 'skipped' => 0];
        }

        $header = str_getcsv((string) array_shift($rows));
        $created = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            if (trim($row) === '') {
                continue;
            }
            $values = str_getcsv($row);
            $record = $this->combineRow($header, $values);
            if (! isset($record['first_name']) || trim((string) $record['first_name']) === '') {
                $skipped++;
                continue;
            }

            $this->createContact($actor, [
                'tenant_id' => $tenantId,
                'first_name' => (string) $record['first_name'],
                'last_name' => $record['last_name'] ?? null,
                'email' => $record['email'] ?? null,
                'phone' => $record['phone'] ?? null,
                'lifecycle_stage' => isset($record['lifecycle_stage'])
                    ? Contact::stageCodeFromString((string) $record['lifecycle_stage'])
                    : Contact::STAGE_LEAD,
            ]);
            $created++;
        }

        Log::info(DomainConstants::LOG_CONTACT_IMPORT_COMPLETED, ['tenant_id' => $tenantId, 'created' => $created, 'skipped' => $skipped]);

        return ['created' => $created, 'skipped' => $skipped];
    }

    public function importCompanies(User $actor, UploadedFile $file, ?int $tenantId = null): array
    {
        $tenantId = $this->resolveTenantId($actor, ['tenant_id' => $tenantId]);
        Log::info(DomainConstants::LOG_COMPANY_IMPORT_STARTED, ['tenant_id' => $tenantId, 'user_id' => $actor->id]);

        $path = Storage::disk(config('filesystems.default'))->putFile('company-imports', $file);
        $content = Storage::disk(config('filesystems.default'))->get($path);
        $rows = preg_split("/\r\n|\n|\r/", trim($content)) ?: [];
        if ($rows === []) {
            return ['created' => 0, 'skipped' => 0];
        }

        $header = str_getcsv((string) array_shift($rows));
        $created = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            if (trim($row) === '') {
                continue;
            }

            $record = $this->combineRow($header, str_getcsv($row));
            if (! isset($record['name']) || trim((string) $record['name']) === '') {
                $skipped++;
                continue;
            }

            $assignedUserId = isset($record['assigned_user_id']) && trim((string) $record['assigned_user_id']) !== ''
                ? (int) $record['assigned_user_id']
                : null;

            $this->createCompany($actor, [
                'tenant_id' => $tenantId,
                'name' => (string) $record['name'],
                'industry' => $record['industry'] ?? null,
                'company_type' => $record['company_type'] ?? null,
                'employees' => isset($record['employees']) && $record['employees'] !== '' ? (int) $record['employees'] : null,
                'revenue' => isset($record['revenue']) && $record['revenue'] !== '' ? (float) $record['revenue'] : null,
                'phone' => $record['phone'] ?? null,
                'email' => $record['email'] ?? null,
                'website' => $record['website'] ?? null,
                'timezone' => $record['timezone'] ?? null,
                'linkedin_url' => $record['linkedin_url'] ?? null,
                'address' => $record['address'] ?? null,
                'city' => $record['city'] ?? null,
                'state' => $record['state'] ?? null,
                'postal_code' => $record['postal_code'] ?? null,
                'country' => $record['country'] ?? null,
                'description' => $record['description'] ?? null,
                'assigned_user_id' => $assignedUserId,
                'status' => isset($record['status']) && (string) $record['status'] === '0'
                    ? Company::STATUS_INACTIVE
                    : Company::STATUS_ACTIVE,
            ]);
            $created++;
        }

        Log::info(DomainConstants::LOG_COMPANY_IMPORT_COMPLETED, ['tenant_id' => $tenantId, 'created' => $created, 'skipped' => $skipped]);

        return ['created' => $created, 'skipped' => $skipped];
    }

    public function exportRows(User $actor, array $filters): array
    {
        Log::info(DomainConstants::LOG_CONTACT_EXPORT, ['tenant_id' => $actor->tenant_id, 'user_id' => $actor->id]);

        return $this->contactRepository
            ->queryForExport($actor, $filters)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Contact $contact): array => [
                'id' => $contact->id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'lifecycle_stage' => $contact->stageLabel(),
                'company_name' => $contact->company?->name,
                'assigned_to' => $contact->assignedUser?->email,
                'created_by' => $contact->createdByUser?->email,
            ])->all();
    }

    public function exportCompanyRows(User $actor, array $filters): array
    {
        Log::info(DomainConstants::LOG_COMPANY_EXPORT, ['tenant_id' => $actor->tenant_id, 'user_id' => $actor->id]);

        return $this->companyRepository
            ->queryForExport($actor, $filters)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Company $company): array => [
                'id' => $company->id,
                'name' => $company->name,
                'industry' => $company->industry,
                'company_type' => $company->company_type,
                'employees' => $company->employees,
                'revenue' => $company->revenue,
                'phone' => $company->phone,
                'email' => $company->email,
                'website' => $company->website,
                'timezone' => $company->timezone,
                'linkedin_url' => $company->linkedin_url,
                'address' => $company->address,
                'city' => $company->city,
                'state' => $company->state,
                'postal_code' => $company->postal_code,
                'country' => $company->country,
                'description' => $company->description,
                'assigned_user' => $company->assignedUser?->email,
                'created_by' => $company->createdByUser?->email,
                'status' => $company->statusLabel(),
            ])->all();
    }

    private function guardTenantForeigns(User $actor, array $payload): void
    {
        if (array_key_exists('company_id', $payload)) {
            $companyId = $payload['company_id'];
            if ($companyId !== null) {
                $company = $this->companyRepository->findById((int) $companyId);
                if (! $company || (int) $company->tenant_id !== (int) $actor->tenant_id) {
                    throw new ModelNotFoundException(DomainConstants::MSG_COMPANY_NOT_FOUND);
                }
            }
        }

        if (isset($payload['assigned_user_id'])) {
            $assignee = User::query()->find((int) $payload['assigned_user_id']);
            if (! $assignee || (int) $assignee->tenant_id !== (int) $actor->tenant_id) {
                throw new ModelNotFoundException(DomainConstants::MSG_UNAUTHORIZED_SCOPE);
            }
        }
    }

    private function mustGetContact(int $id): Contact
    {
        $contact = $this->contactRepository->findById($id);
        if (! $contact) {
            throw new ModelNotFoundException(DomainConstants::MSG_CONTACT_NOT_FOUND);
        }

        return $contact;
    }

    private function guardCompanyAssignee(int $tenantId, ?int $assignedUserId): void
    {
        if (! $assignedUserId) {
            return;
        }

        $assignee = User::query()->find($assignedUserId);
        if (! $assignee || (int) $assignee->tenant_id !== $tenantId) {
            throw new ModelNotFoundException(DomainConstants::MSG_UNAUTHORIZED_SCOPE);
        }
    }

    private function canAccessCompany(User $actor, int $tenantId): bool
    {
        return $actor->isGlobalAdmin() || (int) $actor->tenant_id === $tenantId;
    }

    private function ensureUniqueContactEmail(int $tenantId, ?string $email, ?int $ignoreContactId = null): void
    {
        if (! $email) {
            return;
        }

        if ($this->contactRepository->emailExistsForTenant($tenantId, $email, $ignoreContactId)) {
            throw ValidationException::withMessages([
                'email' => [DomainConstants::MSG_CONTACT_EMAIL_EXISTS],
            ]);
        }
    }

    private function ensureUniqueCompanyEmail(int $tenantId, ?string $email, ?int $ignoreCompanyId = null): void
    {
        if (! $email) {
            return;
        }

        if ($this->companyRepository->emailExistsForTenant($tenantId, $email, $ignoreCompanyId)) {
            throw ValidationException::withMessages([
                'email' => [DomainConstants::MSG_COMPANY_EMAIL_EXISTS],
            ]);
        }
    }

    private function combineRow(array $header, array $values): array
    {
        $row = [];
        foreach ($header as $index => $column) {
            $row[trim((string) $column)] = $values[$index] ?? null;
        }

        return $row;
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

    private function resolveTenantId(User $actor, array $payload): ?int
    {
        if (! $actor->isGlobalAdmin()) {
            return $actor->tenant_id;
        }

        if (! isset($payload['tenant_id'])) {
            throw ValidationException::withMessages(['tenant_id' => ['tenant_id is required for global admin operations.']]);
        }

        return (int) $payload['tenant_id'];
    }
}
