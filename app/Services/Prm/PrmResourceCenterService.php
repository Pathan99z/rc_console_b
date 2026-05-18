<?php

namespace App\Services\Prm;

use App\Models\Collateral;
use App\Models\CollateralDownload;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Services\Auth\PermissionResolverService;
use App\Support\Prm\PartnerResourceVisibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use App\Support\Storage\EnterpriseStorage;
use Illuminate\Validation\ValidationException;

/**
 * Partner-facing PRM Resource Center. Collateral rows are the backing store;
 * program-tier visibility can extend {@see PartnerResourceVisibility} later.
 */
class PrmResourceCenterService
{
    public function __construct(
        private readonly PermissionResolverService $permissionResolver,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly EnterpriseStorage $storage,
    ) {}

    public function listForPartner(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        if (! $this->permissionResolver->can($actor, 'prm.resources.view')) {
            throw ValidationException::withMessages(['resource' => ['Not allowed to access partner resources.']]);
        }

        $q = Collateral::query()
            ->with(['product'])
            ->where('tenant_id', $actor->tenant_id);

        PartnerResourceVisibility::applyPartnerListScope($q, $actor);

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

    /**
     * Curated partner payload (additive vs legacy CollateralResource keys where applicable).
     *
     * @return array<string, mixed>
     */
    public function partnerResourcePayload(Collateral $collateral): array
    {
        $collateral->setAttribute('signed_url', $this->generateSignedUrl($collateral->file_key));

        return [
            'id' => $collateral->id,
            'title' => $collateral->name,
            'name' => $collateral->name,
            'description' => $collateral->description,
            'category' => $collateral->resource_category,
            'resource_category' => $collateral->resource_category,
            'product_id' => $collateral->product_id,
            'product_name' => $collateral->product?->name,
            'file' => [
                'file_type' => $collateral->file_type,
                'file_size' => $collateral->file_size,
            ],
            'signed_url' => $collateral->signed_url,
            'type' => $collateral->type,
            'created_at' => $collateral->created_at?->toIso8601String(),
        ];
    }

    public function recordDownload(User $actor, int $collateralId, ?string $ip, ?string $ua): void
    {
        if (! $this->permissionResolver->can($actor, 'prm.resources.view')) {
            throw ValidationException::withMessages(['resource' => ['Not allowed to record downloads.']]);
        }

        $c = Collateral::query()->whereKey($collateralId)->where('tenant_id', $actor->tenant_id)->first();
        if (! $c) {
            throw ValidationException::withMessages(['collateral' => ['Resource not found.']]);
        }

        if (! PartnerResourceVisibility::canPartnerAccessCollateral($actor, $c)) {
            throw ValidationException::withMessages(['collateral' => ['Resource is not available for download.']]);
        }

        $partnerOrgId = $actor->primaryOrganizationId();
        if (! $partnerOrgId) {
            throw ValidationException::withMessages(['organization' => ['No channel organization assignment found for this user.']]);
        }

        $now = now();
        $download = CollateralDownload::query()->create([
            'tenant_id' => (int) $actor->tenant_id,
            'collateral_id' => $c->id,
            'user_id' => $actor->id,
            'partner_organization_id' => $partnerOrgId,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'downloaded_at' => $now,
        ]);

        $this->auditLogRepository->create([
            'tenant_id' => (int) $actor->tenant_id,
            'user_id' => $actor->id,
            'module' => 'prm',
            'action' => 'prm.resource.downloaded',
            'entity_type' => 'collateral_download',
            'entity_id' => $download->id,
            'before' => null,
            'after' => [
                'collateral_id' => $c->id,
                'partner_organization_id' => $partnerOrgId,
            ],
            'ip_address' => $ip,
            'user_agent' => $ua,
        ]);
    }

    private function generateSignedUrl(string $fileKey): string
    {
        return $this->storage->signedUrl(
            $fileKey,
            (int) config('enterprise_storage.collateral_signed_url_minutes', 10),
            EnterpriseStorage::PURPOSE_COLLATERAL
        );
    }
}
