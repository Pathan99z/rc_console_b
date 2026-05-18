<?php

namespace App\Services\Product;

use App\Models\Product;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\ProductRepository;
use App\Support\DomainConstants;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use App\Services\Cache\CacheInvalidationService;
use App\Support\Cache\TenantListCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly TenantListCache $tenantListCache,
        private readonly CacheInvalidationService $cacheInvalidation,
    ) {
    }

    public function listProducts(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $tenantId = $actor->isGlobalAdmin() ? ($filters['tenant_id'] ?? null) : $actor->tenant_id;
        return $this->tenantListCache->remember(
            $actor,
            'products',
            $tenantId,
            $filters,
            $perPage,
            (int) config('enterprise_cache.ttl.list_products', 600),
            fn () => $this->productRepository->paginateFiltered($actor, $filters, $perPage),
        );
    }

    public function createProduct(User $actor, array $payload, Request $request): Product
    {
        $tenantId = $this->resolveTenantId($actor, $payload);
        $sku = $this->normalizeSku($payload['sku'] ?? null);
        $this->ensureUniqueSku($tenantId, $sku);

        $product = $this->productRepository->create([
            'tenant_id' => $tenantId,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
            'name' => trim((string) $payload['name']),
            'description' => $payload['description'] ?? null,
            'sku' => $sku,
            'unit_price' => $payload['unit_price'],
            'tax_rate' => $payload['tax_rate'] ?? null,
            'status' => (int) ($payload['status'] ?? Product::STATUS_ACTIVE),
        ]);

        $this->recordAudit($actor, $request, 'created', $product, null, $product->toArray());
        Log::info(DomainConstants::LOG_PRODUCT_CREATED, ['tenant_id' => $tenantId, 'product_id' => $product->id]);
        $this->cacheInvalidation->afterProductMutation((int) $tenantId);

        return $this->mustGetProduct($product->id);
    }

    public function getProduct(User $actor, int $productId): Product
    {
        $product = $this->mustGetProduct($productId);
        if (! $this->canViewProduct($actor, $product)) {
            throw new ModelNotFoundException(DomainConstants::MSG_PRODUCT_NOT_FOUND);
        }

        return $product;
    }

    public function updateProduct(User $actor, int $productId, array $payload, Request $request): Product
    {
        $product = $this->getProduct($actor, $productId);
        if (! $this->canUpdateProduct($actor, $product)) {
            throw new ModelNotFoundException(DomainConstants::MSG_PRODUCT_NOT_FOUND);
        }

        $before = $product->toArray();
        if (array_key_exists('sku', $payload)) {
            $payload['sku'] = $this->normalizeSku($payload['sku']);
            $this->ensureUniqueSku((int) $product->tenant_id, $payload['sku'], $product->id);
        }
        if (array_key_exists('name', $payload)) {
            $payload['name'] = trim((string) $payload['name']);
        }
        $payload['updated_by_user_id'] = $actor->id;

        $updated = $this->productRepository->update($product, $payload);
        $this->recordAudit($actor, $request, 'updated', $updated, $before, $updated->toArray());
        Log::info(DomainConstants::LOG_PRODUCT_UPDATED, ['tenant_id' => $updated->tenant_id, 'product_id' => $updated->id]);
        $this->cacheInvalidation->afterProductMutation((int) $updated->tenant_id);

        return $this->mustGetProduct($updated->id);
    }

    public function deleteProduct(User $actor, int $productId, Request $request): void
    {
        $product = $this->getProduct($actor, $productId);
        if (! $actor->isCompanyAdmin() && ! $actor->isGlobalAdmin()) {
            throw ValidationException::withMessages(['product' => [DomainConstants::MSG_PRODUCT_DELETE_FORBIDDEN]]);
        }
        if ($this->isUsedInQuote($product->id)) {
            throw ValidationException::withMessages(['product' => [DomainConstants::MSG_PRODUCT_USED_IN_QUOTE]]);
        }

        $before = $product->toArray();
        $this->productRepository->delete($product);
        $this->recordAudit($actor, $request, 'deleted', $product, $before, null);
        Log::info(DomainConstants::LOG_PRODUCT_DELETED, ['tenant_id' => $product->tenant_id, 'product_id' => $product->id]);
        $this->cacheInvalidation->afterProductMutation((int) $product->tenant_id);
    }

    public function updateStatus(User $actor, int $productId, int $status, Request $request): Product
    {
        $product = $this->getProduct($actor, $productId);
        if (! $this->canUpdateProduct($actor, $product)) {
            throw new ModelNotFoundException(DomainConstants::MSG_PRODUCT_NOT_FOUND);
        }

        $before = $product->toArray();
        $updated = $this->productRepository->update($product, [
            'status' => $status,
            'updated_by_user_id' => $actor->id,
        ]);
        $this->recordAudit($actor, $request, 'status_changed', $updated, $before, $updated->toArray());
        Log::info(DomainConstants::LOG_PRODUCT_STATUS_CHANGED, ['tenant_id' => $updated->tenant_id, 'product_id' => $updated->id, 'status' => $status]);
        $this->cacheInvalidation->afterProductMutation((int) $updated->tenant_id);

        return $this->mustGetProduct($updated->id);
    }

    private function mustGetProduct(int $productId): Product
    {
        $product = $this->productRepository->findById($productId);
        if (! $product) {
            throw new ModelNotFoundException(DomainConstants::MSG_PRODUCT_NOT_FOUND);
        }

        return $product;
    }

    private function ensureUniqueSku(int $tenantId, ?string $sku, ?int $ignoreProductId = null): void
    {
        if (! $sku) {
            return;
        }

        if ($this->productRepository->skuExistsForTenant($tenantId, $sku, $ignoreProductId)) {
            throw ValidationException::withMessages(['sku' => [DomainConstants::MSG_PRODUCT_SKU_EXISTS]]);
        }
    }

    private function normalizeSku(?string $sku): ?string
    {
        if ($sku === null) {
            return null;
        }

        $normalized = strtoupper(trim($sku));

        return $normalized !== '' ? $normalized : null;
    }

    private function resolveTenantId(User $actor, array $payload): ?int
    {
        if (! $actor->isGlobalAdmin()) {
            return $actor->tenant_id;
        }

        if (! isset($payload['tenant_id'])) {
            throw ValidationException::withMessages(['tenant_id' => [DomainConstants::MSG_TENANT_REQUIRED]]);
        }

        return (int) $payload['tenant_id'];
    }

    private function canViewProduct(User $actor, Product $product): bool
    {
        if ($actor->isGlobalAdmin()) {
            return true;
        }

        if ((int) $product->tenant_id !== (int) $actor->tenant_id) {
            return false;
        }

        if ($actor->isCompanyAdmin()) {
            return true;
        }

        if ((int) $product->created_by_user_id === (int) $actor->id) {
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

        return in_array((int) $product->created_by_user_id, array_map('intval', $teamUserIds), true);
    }

    private function canUpdateProduct(User $actor, Product $product): bool
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return true;
        }

        return $this->canViewProduct($actor, $product);
    }

    private function isUsedInQuote(int $productId): bool
    {
        if (! Schema::hasTable('quote_items')) {
            return false;
        }

        return DB::table('quote_items')->where('product_id', $productId)->exists();
    }

    private function recordAudit(User $actor, Request $request, string $action, Product $product, ?array $before, ?array $after): void
    {
        $this->auditLogRepository->create([
            'tenant_id' => $product->tenant_id,
            'user_id' => $actor->id,
            'module' => 'product',
            'action' => $action,
            'entity_type' => Product::class,
            'entity_id' => $product->id,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

}
