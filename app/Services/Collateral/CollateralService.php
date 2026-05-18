<?php

namespace App\Services\Collateral;

use App\Mail\CollateralSharedMail;
use App\Models\Collateral;
use App\Models\Contact;
use App\Models\Product;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\CollateralRepository;
use App\Support\DomainConstants;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use App\Services\Cache\CacheInvalidationService;
use App\Support\Cache\TenantListCache;
use App\Support\Mail\EnterpriseMailDispatcher;
use App\Support\Storage\EnterpriseStorage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CollateralService
{
    public function __construct(
        private readonly CollateralRepository $collateralRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly TenantListCache $tenantListCache,
        private readonly CacheInvalidationService $cacheInvalidation,
        private readonly EnterpriseMailDispatcher $mailDispatcher,
        private readonly EnterpriseStorage $storage,
    ) {
    }

    public function listCollaterals(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $tenantId = $actor->isGlobalAdmin() ? ($filters['tenant_id'] ?? null) : $actor->tenant_id;
        return $this->tenantListCache->remember(
            $actor,
            'collaterals',
            $tenantId,
            $filters,
            $perPage,
            (int) config('enterprise_cache.ttl.list_collaterals', 600),
            fn () => $this->collateralRepository->paginateFiltered($actor, $filters, $perPage),
        );
    }

    public function upload(User $actor, array $payload, UploadedFile $file, Request $request): Collateral
    {
        $tenantId = $this->resolveTenantId($actor, $payload);
        $product = $this->mustGetTenantProduct($tenantId, (int) $payload['product_id']);

        $fileName = $this->buildStoredFileName($file);
        $fileKey = "tenant/{$tenantId}/collaterals/{$fileName}";
        $this->storage->putPrivate($fileKey, $file->getContent(), EnterpriseStorage::PURPOSE_COLLATERAL);

        $collateral = $this->collateralRepository->create([
            'tenant_id' => $tenantId,
            'product_id' => $product->id,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
            'name' => trim((string) $payload['name']),
            'type' => trim((string) $payload['type']),
            'file_key' => $fileKey,
            'file_type' => (string) ($file->getMimeType() ?? $file->getClientMimeType() ?? 'application/octet-stream'),
            'file_size' => (int) $file->getSize(),
        ]);

        $this->recordAudit($actor, $request, 'uploaded', $collateral, null, $collateral->toArray());
        Log::info(DomainConstants::LOG_COLLATERAL_UPLOADED, ['tenant_id' => $tenantId, 'collateral_id' => $collateral->id]);
        $this->cacheInvalidation->afterCollateralMutation($tenantId);

        return $this->mustGetCollateral($collateral->id);
    }

    public function getCollateral(User $actor, int $collateralId): Collateral
    {
        $collateral = $this->mustGetCollateral($collateralId);
        if (! $this->canAccessCollateral($actor, $collateral)) {
            throw new ModelNotFoundException(DomainConstants::MSG_COLLATERAL_NOT_FOUND);
        }

        $collateral->setAttribute('signed_url', $this->generateSignedUrl($collateral->file_key));

        return $collateral;
    }

    public function delete(User $actor, int $collateralId, Request $request): void
    {
        $collateral = $this->mustGetCollateral($collateralId);
        if (! $this->canAccessCollateral($actor, $collateral)) {
            throw new ModelNotFoundException(DomainConstants::MSG_COLLATERAL_NOT_FOUND);
        }
        if (! $actor->isCompanyAdmin() && ! $actor->isGlobalAdmin()) {
            throw ValidationException::withMessages(['collateral' => [DomainConstants::MSG_COLLATERAL_DELETE_FORBIDDEN]]);
        }

        $before = $collateral->toArray();
        $this->storage->delete($collateral->file_key, EnterpriseStorage::PURPOSE_COLLATERAL);
        $this->collateralRepository->delete($collateral);
        $this->recordAudit($actor, $request, 'deleted', $collateral, $before, null);
        Log::info(DomainConstants::LOG_COLLATERAL_DELETED, ['tenant_id' => $collateral->tenant_id, 'collateral_id' => $collateral->id]);
        $this->cacheInvalidation->afterCollateralMutation((int) $collateral->tenant_id);
    }

    public function send(User $actor, int $collateralId, array $payload, Request $request): void
    {
        $collateral = $this->mustGetCollateral($collateralId);
        if (! $this->canAccessCollateral($actor, $collateral)) {
            throw new ModelNotFoundException(DomainConstants::MSG_COLLATERAL_NOT_FOUND);
        }

        $recipientEmail = $this->resolveRecipientEmail((int) $collateral->tenant_id, $payload);
        $signedUrl = $this->generateSignedUrl($collateral->file_key);
        $productName = $collateral->product?->name ?? 'Product';

        $this->mailDispatcher->send($recipientEmail, new CollateralSharedMail(
            productName: $productName,
            collateralName: $collateral->name,
            signedUrl: $signedUrl,
            messageText: $payload['message'] ?? null
        ));

        $this->recordAudit($actor, $request, 'sent', $collateral, null, [
            'recipient_email' => $recipientEmail,
            'product_name' => $productName,
        ]);
        Log::info(DomainConstants::LOG_COLLATERAL_SENT, ['tenant_id' => $collateral->tenant_id, 'collateral_id' => $collateral->id]);
    }

    private function resolveRecipientEmail(int $tenantId, array $payload): string
    {
        if (isset($payload['email']) && trim((string) $payload['email']) !== '') {
            return (string) $payload['email'];
        }

        $contactId = (int) ($payload['contact_id'] ?? 0);
        $contact = Contact::query()
            ->where('id', $contactId)
            ->where('tenant_id', $tenantId)
            ->first();
        if (! $contact || ! $contact->email) {
            throw ValidationException::withMessages([
                'contact_id' => [DomainConstants::MSG_COLLATERAL_CONTACT_EMAIL_REQUIRED],
            ]);
        }

        return (string) $contact->email;
    }

    private function mustGetTenantProduct(int $tenantId, int $productId): Product
    {
        $product = Product::query()
            ->where('id', $productId)
            ->where('tenant_id', $tenantId)
            ->first();
        if (! $product) {
            throw ValidationException::withMessages([
                'product_id' => [DomainConstants::MSG_COLLATERAL_INVALID_PRODUCT],
            ]);
        }

        return $product;
    }

    private function mustGetCollateral(int $collateralId): Collateral
    {
        $collateral = $this->collateralRepository->findById($collateralId);
        if (! $collateral) {
            throw new ModelNotFoundException(DomainConstants::MSG_COLLATERAL_NOT_FOUND);
        }

        return $collateral;
    }

    private function canAccessCollateral(User $actor, Collateral $collateral): bool
    {
        if ($actor->isGlobalAdmin()) {
            return true;
        }

        return (int) $actor->tenant_id === (int) $collateral->tenant_id;
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

    private function buildStoredFileName(UploadedFile $file): string
    {
        $safeOriginalName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $extension = strtolower($file->getClientOriginalExtension());
        $safeOriginalName = $safeOriginalName !== '' ? $safeOriginalName : 'file';

        return sprintf('%s-%s.%s', Str::uuid()->toString(), $safeOriginalName, $extension);
    }

    private function generateSignedUrl(string $fileKey): string
    {
        return $this->storage->signedUrl(
            $fileKey,
            (int) config('enterprise_storage.collateral_signed_url_minutes', 10),
            EnterpriseStorage::PURPOSE_COLLATERAL
        );
    }

    private function recordAudit(User $actor, Request $request, string $action, Collateral $collateral, ?array $before, ?array $after): void
    {
        $this->auditLogRepository->create([
            'tenant_id' => $collateral->tenant_id,
            'user_id' => $actor->id,
            'module' => 'collateral',
            'action' => $action,
            'entity_type' => Collateral::class,
            'entity_id' => $collateral->id,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

}
