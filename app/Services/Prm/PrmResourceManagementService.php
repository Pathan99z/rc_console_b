<?php

namespace App\Services\Prm;

use App\Models\Collateral;
use App\Models\Product;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Support\DomainConstants;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use App\Services\Cache\CacheInvalidationService;
use App\Support\Storage\EnterpriseStorage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin PRM resource (collateral) lifecycle. Extensible for program-scoped visibility later.
 */
class PrmResourceManagementService
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly CacheInvalidationService $cacheInvalidation,
        private readonly EnterpriseStorage $storage,
    ) {}

    public function listForAdmin(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $tenantId = $this->resolveTenantIdForList($actor, $filters);

        $q = Collateral::query()
            ->with(['product'])
            ->withCount('downloads as download_count')
            ->where('tenant_id', $tenantId);

        $status = (string) ($filters['status'] ?? 'all');
        if ($status === 'active') {
            $q->where('status', Collateral::STATUS_ACTIVE);
        } elseif ($status === 'inactive') {
            $q->where('status', Collateral::STATUS_INACTIVE);
        }

        if (! empty($filters['resource_category'])) {
            $q->where('resource_category', (string) $filters['resource_category']);
        }

        if (! empty($filters['product_id'])) {
            $q->where('product_id', (int) $filters['product_id']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $q->where(function (Builder $inner) use ($term): void {
                $inner->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('resource_category', 'like', $term);
            });
        }

        return $q->orderByDesc('id')->paginate($perPage);
    }

    public function showForAdmin(User $actor, int $collateralId, array $filters): Collateral
    {
        $tenantId = $this->resolveTenantIdForMutation($actor, $filters);
        $row = Collateral::query()
            ->with(['product'])
            ->withCount('downloads as download_count')
            ->where('tenant_id', $tenantId)
            ->whereKey($collateralId)
            ->first();
        if (! $row) {
            throw new ModelNotFoundException(DomainConstants::MSG_COLLATERAL_NOT_FOUND);
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function store(User $actor, array $validated, UploadedFile $file, Request $request): Collateral
    {
        $tenantId = $this->resolveTenantIdForMutation($actor, $validated);
        $this->assertProductBelongsToTenant($tenantId, $validated['product_id'] ?? null);

        $fileKey = $this->storeUploadedFile($tenantId, $file);
        $mime = (string) ($file->getMimeType() ?? $file->getClientMimeType() ?? 'application/octet-stream');

        $collateral = Collateral::query()->create([
            'tenant_id' => $tenantId,
            'product_id' => $validated['product_id'] ?? null,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
            'name' => trim((string) $validated['title']),
            'description' => isset($validated['description']) ? trim((string) $validated['description']) : null,
            'type' => Str::limit(trim((string) $validated['resource_category']), 100, ''),
            'file_key' => $fileKey,
            'file_type' => $mime,
            'file_size' => (int) $file->getSize(),
            'partner_visible' => (bool) $validated['partner_visible'],
            'reseller_visible' => (bool) $validated['reseller_visible'],
            'resource_category' => trim((string) $validated['resource_category']),
            'status' => (string) $validated['status'],
            'metadata' => $validated['metadata'] ?? null,
        ]);

        $this->auditPrm($actor, $request, 'prm.resource.created', $collateral, null, $collateral->fresh()?->toArray());
        Log::info('prm.resource.created', ['tenant_id' => $tenantId, 'collateral_id' => $collateral->id]);
        $this->cacheInvalidation->afterCollateralMutation($tenantId);

        return $this->showForAdmin($actor, $collateral->id, $validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(User $actor, int $collateralId, array $validated, ?UploadedFile $file, Request $request): Collateral
    {
        $tenantId = $this->resolveTenantIdForMutation($actor, $validated);
        $collateral = Collateral::query()->where('tenant_id', $tenantId)->whereKey($collateralId)->first();
        if (! $collateral) {
            throw new ModelNotFoundException(DomainConstants::MSG_COLLATERAL_NOT_FOUND);
        }

        $before = $collateral->toArray();
        $nextProductId = array_key_exists('product_id', $validated) ? $validated['product_id'] : $collateral->product_id;
        $this->assertProductBelongsToTenant($tenantId, $nextProductId);

        $updates = ['updated_by_user_id' => $actor->id];
        if (array_key_exists('title', $validated)) {
            $updates['name'] = trim((string) $validated['title']);
        }
        if (array_key_exists('description', $validated)) {
            $updates['description'] = $validated['description'] !== null ? trim((string) $validated['description']) : null;
        }
        if (array_key_exists('resource_category', $validated)) {
            $cat = trim((string) $validated['resource_category']);
            $updates['resource_category'] = $cat;
            $updates['type'] = Str::limit($cat, 100, '');
        }
        if (array_key_exists('product_id', $validated)) {
            $updates['product_id'] = $validated['product_id'];
        }
        if (array_key_exists('partner_visible', $validated)) {
            $updates['partner_visible'] = (bool) $validated['partner_visible'];
        }
        if (array_key_exists('reseller_visible', $validated)) {
            $updates['reseller_visible'] = (bool) $validated['reseller_visible'];
        }
        if (array_key_exists('status', $validated)) {
            $updates['status'] = (string) $validated['status'];
        }
        if (array_key_exists('metadata', $validated)) {
            $updates['metadata'] = $validated['metadata'];
        }

        if ($file instanceof UploadedFile) {
            $this->storage->delete($collateral->file_key, EnterpriseStorage::PURPOSE_COLLATERAL);
            $updates['file_key'] = $this->storeUploadedFile($tenantId, $file);
            $updates['file_type'] = (string) ($file->getMimeType() ?? $file->getClientMimeType() ?? 'application/octet-stream');
            $updates['file_size'] = (int) $file->getSize();
        }

        $collateral->update($updates);
        $fresh = $collateral->fresh();
        $this->auditPrm($actor, $request, 'prm.resource.updated', $fresh, $before, $fresh?->toArray());
        Log::info('prm.resource.updated', ['tenant_id' => $tenantId, 'collateral_id' => $collateralId]);
        $this->cacheInvalidation->afterCollateralMutation($tenantId);

        return $this->showForAdmin($actor, $collateralId, $validated);
    }

    public function updateStatus(User $actor, int $collateralId, string $status, Request $request, array $context = []): Collateral
    {
        $tenantId = $this->resolveTenantIdForMutation($actor, $context);
        $collateral = Collateral::query()->where('tenant_id', $tenantId)->whereKey($collateralId)->first();
        if (! $collateral) {
            throw new ModelNotFoundException(DomainConstants::MSG_COLLATERAL_NOT_FOUND);
        }
        $before = $collateral->toArray();
        $collateral->update([
            'status' => $status,
            'updated_by_user_id' => $actor->id,
        ]);
        $fresh = $collateral->fresh();
        $this->auditPrm($actor, $request, 'prm.resource.status', $fresh, $before, $fresh?->toArray());
        $this->cacheInvalidation->afterCollateralMutation($tenantId);

        return $this->showForAdmin($actor, $collateralId, $context);
    }

    public function delete(User $actor, int $collateralId, Request $request, array $context = []): void
    {
        $tenantId = $this->resolveTenantIdForMutation($actor, $context);
        $collateral = Collateral::query()->where('tenant_id', $tenantId)->whereKey($collateralId)->first();
        if (! $collateral) {
            throw new ModelNotFoundException(DomainConstants::MSG_COLLATERAL_NOT_FOUND);
        }
        $before = $collateral->toArray();
        $collateral->delete();
        $this->auditPrm($actor, $request, 'prm.resource.deleted', $collateral, $before, null);
        Log::info('prm.resource.deleted', ['tenant_id' => $tenantId, 'collateral_id' => $collateralId]);
        $this->cacheInvalidation->afterCollateralMutation($tenantId);
    }

    private function resolveTenantIdForList(User $actor, array $filters): int
    {
        if ($actor->isGlobalAdmin()) {
            if (empty($filters['tenant_id'])) {
                throw ValidationException::withMessages([
                    'tenant_id' => [DomainConstants::MSG_TENANT_REQUIRED],
                ]);
            }

            return (int) $filters['tenant_id'];
        }

        return (int) $actor->tenant_id;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveTenantIdForMutation(User $actor, array $payload): int
    {
        if ($actor->isGlobalAdmin()) {
            if (empty($payload['tenant_id'])) {
                throw ValidationException::withMessages([
                    'tenant_id' => [DomainConstants::MSG_TENANT_REQUIRED],
                ]);
            }

            return (int) $payload['tenant_id'];
        }

        return (int) $actor->tenant_id;
    }

    private function assertProductBelongsToTenant(int $tenantId, ?int $productId): void
    {
        if ($productId === null) {
            return;
        }
        $exists = Product::query()->where('id', $productId)->where('tenant_id', $tenantId)->exists();
        if (! $exists) {
            throw ValidationException::withMessages([
                'product_id' => [DomainConstants::MSG_COLLATERAL_INVALID_PRODUCT],
            ]);
        }
    }

    private function storeUploadedFile(int $tenantId, UploadedFile $file): string
    {
        $fileName = $this->buildStoredFileName($file);
        $fileKey = "tenant/{$tenantId}/collaterals/{$fileName}";
        $this->storage->putPrivate($fileKey, $file->getContent(), EnterpriseStorage::PURPOSE_COLLATERAL);

        return $fileKey;
    }

    private function buildStoredFileName(UploadedFile $file): string
    {
        $safeOriginalName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $extension = strtolower($file->getClientOriginalExtension());
        $safeOriginalName = $safeOriginalName !== '' ? $safeOriginalName : 'file';

        return sprintf('%s-%s.%s', Str::uuid()->toString(), $safeOriginalName, $extension);
    }

    private function auditPrm(User $actor, Request $request, string $action, Collateral $collateral, ?array $before, ?array $after): void
    {
        $this->auditLogRepository->create([
            'tenant_id' => $collateral->tenant_id,
            'user_id' => $actor->id,
            'module' => 'prm',
            'action' => $action,
            'entity_type' => 'collateral',
            'entity_id' => $collateral->id,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
