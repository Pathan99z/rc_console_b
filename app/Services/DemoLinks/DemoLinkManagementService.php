<?php

namespace App\Services\DemoLinks;

use App\Models\DemoLink;
use App\Models\DemoLinkVisibility;
use App\Models\Product;
use App\Models\User;
use App\Services\Payment\PaymentSecretEncrypter;
use App\Support\DemoLinks\DemoLinkAccessScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use App\Support\Storage\EnterpriseStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DemoLinkManagementService
{
    public function __construct(
        private readonly DemoLinkAccessScope $accessScope,
        private readonly DemoLinkAuditLogger $auditLogger,
        private readonly DemoLinkStatusChecker $statusChecker,
        private readonly PaymentSecretEncrypter $encrypter,
        private readonly EnterpriseStorage $storage,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listForActor(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $q = DemoLink::query()
            ->with(['creator', 'ownerOrganization', 'products', 'visibilities.organization'])
            ->orderByDesc('id');

        $this->accessScope->applyListScope($q, $actor);

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $q->where(function ($inner) use ($search): void {
                $inner->where('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhere('demo_url', 'like', '%'.$search.'%');
            });
        }

        if (isset($filters['is_active'])) {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['owner_organization_id'])) {
            $q->where('owner_organization_id', (int) $filters['owner_organization_id']);
        }

        if (! empty($filters['product_id'])) {
            $q->whereHas('products', fn ($p) => $p->where('products.id', (int) $filters['product_id']));
        }

        return $q->paginate($perPage);
    }

    public function getForActor(User $actor, int $id, bool $revealCredentials = false): DemoLink
    {
        $link = $this->loadDemoLink($id);
        $this->accessScope->assertCanViewDemoLink($actor, $link);

        if ($revealCredentials && ! $this->accessScope->canRevealCredentials($actor, $link)) {
            throw ValidationException::withMessages([
                'credentials' => ['You are not allowed to reveal demo credentials.'],
            ]);
        }

        return $link;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data, ?UploadedFile $screenshot = null, ?string $ip = null, ?string $ua = null): DemoLink
    {
        $tenantId = (int) $actor->tenant_id;
        $ownerOrgId = (int) $data['owner_organization_id'];
        $this->accessScope->assertOwnerOrganizationAllowed($actor, $ownerOrgId);

        $visibility = $data['visibility'] ?? [];
        if (is_array($visibility) && $visibility !== []) {
            $this->accessScope->assertVisibilityOrganizationsAllowed($actor, $visibility);
        }

        return DB::transaction(function () use ($actor, $data, $screenshot, $ip, $ua, $tenantId, $ownerOrgId, $visibility): DemoLink {
            $link = DemoLink::query()->create([
                'tenant_id' => $tenantId,
                'created_by_user_id' => $actor->id,
                'owner_organization_id' => $ownerOrgId,
                'title' => (string) $data['title'],
                'demo_url' => (string) $data['demo_url'],
                'demo_username' => $data['demo_username'] ?? null,
                'demo_password_encrypted' => isset($data['demo_password'])
                    ? $this->encrypter->encrypt((string) $data['demo_password'])
                    : null,
                'description' => $data['description'] ?? null,
                'check_live_status' => (bool) ($data['check_live_status'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'metadata' => $data['metadata'] ?? null,
            ]);

            if ($screenshot) {
                $link->update([
                    'screenshot_path' => $this->storage->storeUploadedFile(
                        $screenshot,
                        "tenant/{$tenantId}/demo-links/{$link->id}"
                    ),
                ]);
            }

            $this->syncProducts($link, $data['product_ids'] ?? []);
            $this->syncVisibility($link, is_array($visibility) ? $visibility : []);

            $fresh = $this->loadDemoLink($link->id);
            $this->auditLogger->log($actor, 'demo_links.create', $fresh, null, $this->auditPayload($fresh), $ip, $ua);

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, int $id, array $data, ?UploadedFile $screenshot = null, ?string $ip = null, ?string $ua = null): DemoLink
    {
        $link = $this->loadDemoLink($id);
        $this->accessScope->assertCanManageDemoLink($actor, $link);
        $before = $this->auditPayload($link);

        if (isset($data['owner_organization_id'])) {
            $this->accessScope->assertOwnerOrganizationAllowed($actor, (int) $data['owner_organization_id']);
            $link->owner_organization_id = (int) $data['owner_organization_id'];
        }

        if (isset($data['title'])) {
            $link->title = (string) $data['title'];
        }
        if (array_key_exists('description', $data)) {
            $link->description = $data['description'];
        }
        if (isset($data['demo_url'])) {
            $link->demo_url = (string) $data['demo_url'];
        }
        if (array_key_exists('demo_username', $data)) {
            $link->demo_username = $data['demo_username'];
        }
        if (array_key_exists('demo_password', $data) && $data['demo_password'] !== null && $data['demo_password'] !== '') {
            $link->demo_password_encrypted = $this->encrypter->encrypt((string) $data['demo_password']);
        }
        if (isset($data['check_live_status'])) {
            $link->check_live_status = (bool) $data['check_live_status'];
        }
        if (isset($data['is_active'])) {
            $link->is_active = (bool) $data['is_active'];
        }
        if (array_key_exists('metadata', $data)) {
            $link->metadata = $data['metadata'];
        }

        if ($screenshot) {
            if ($link->screenshot_path) {
                $this->storage->delete($link->screenshot_path);
            }
            $link->screenshot_path = $this->storage->storeUploadedFile(
                $screenshot,
                "tenant/{$link->tenant_id}/demo-links/{$link->id}"
            );
        }

        $link->save();

        if (array_key_exists('product_ids', $data)) {
            $this->syncProducts($link, is_array($data['product_ids']) ? $data['product_ids'] : []);
        }

        if (array_key_exists('visibility', $data)) {
            $visibility = is_array($data['visibility']) ? $data['visibility'] : [];
            $this->accessScope->assertVisibilityOrganizationsAllowed($actor, $visibility);
            $this->syncVisibility($link, $visibility);
        }

        $fresh = $this->loadDemoLink($link->id);
        $this->auditLogger->log($actor, 'demo_links.update', $fresh, $before, $this->auditPayload($fresh), $ip, $ua);

        return $fresh;
    }

    public function delete(User $actor, int $id, ?string $ip = null, ?string $ua = null): void
    {
        $link = $this->loadDemoLink($id);
        $this->accessScope->assertCanManageDemoLink($actor, $link);
        $before = $this->auditPayload($link);

        if ($link->screenshot_path) {
            $this->storage->delete($link->screenshot_path);
        }

        $link->delete();
        $this->auditLogger->log($actor, 'demo_links.delete', $link, $before, null, $ip, $ua);
    }

    /**
     * @return array<string, mixed>
     */
    public function checkStatus(User $actor, int $id): array
    {
        $link = $this->loadDemoLink($id);
        $this->accessScope->assertCanViewDemoLink($actor, $link);

        $result = $this->statusChecker->check($link);
        $link->update([
            'last_status' => $result['last_status'],
            'last_checked_at' => $result['last_checked_at'],
        ]);

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function shareableOrganizations(User $actor): array
    {
        $ids = $this->accessScope->shareableOrganizationIds($actor);

        return \App\Models\Organization::query()
            ->whereIn('id', $ids)
            ->orderBy('display_name')
            ->get(['id', 'type', 'display_name', 'legal_name', 'parent_organization_id'])
            ->map(fn ($org) => [
                'id' => $org->id,
                'type' => $org->type,
                'display_name' => $org->display_name,
                'legal_name' => $org->legal_name,
                'parent_organization_id' => $org->parent_organization_id,
            ])
            ->values()
            ->all();
    }

    public function decryptPassword(DemoLink $link): ?string
    {
        return $this->encrypter->decrypt($link->demo_password_encrypted);
    }

    private function loadDemoLink(int $id): DemoLink
    {
        $link = DemoLink::query()->find($id);
        if (! $link) {
            throw new ModelNotFoundException('Demo link not found.');
        }

        return $link->load(['creator', 'ownerOrganization', 'products', 'visibilities.organization']);
    }

    /**
     * @param  list<int>  $productIds
     */
    private function syncProducts(DemoLink $link, array $productIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if ($ids !== []) {
            $valid = Product::query()
                ->where('tenant_id', $link->tenant_id)
                ->whereIn('id', $ids)
                ->pluck('id')
                ->all();
            if (count($valid) !== count($ids)) {
                throw ValidationException::withMessages([
                    'product_ids' => ['One or more products are invalid for this tenant.'],
                ]);
            }
            $ids = $valid;
        }

        $syncPayload = [];
        foreach ($ids as $productId) {
            $syncPayload[$productId] = ['tenant_id' => $link->tenant_id];
        }

        $link->products()->sync($syncPayload);
    }

    /**
     * @param  list<array<string, mixed>>  $visibility
     */
    private function syncVisibility(DemoLink $link, array $visibility): void
    {
        DemoLinkVisibility::query()->where('demo_link_id', $link->id)->delete();

        foreach ($visibility as $row) {
            $orgId = (int) ($row['organization_id'] ?? 0);
            if ($orgId <= 0) {
                continue;
            }

            DemoLinkVisibility::query()->create([
                'tenant_id' => $link->tenant_id,
                'demo_link_id' => $link->id,
                'organization_id' => $orgId,
                'include_children' => (bool) ($row['include_children'] ?? false),
                'visibility_type' => (string) ($row['visibility_type'] ?? DemoLink::VISIBILITY_VIEW),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(DemoLink $link): array
    {
        $payload = $link->toArray();
        unset($payload['demo_password_encrypted']);

        return $payload;
    }
}
