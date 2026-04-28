<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\User;
use App\Support\DomainConstants;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ProductRepository
{
    public function paginateFiltered(User $actor, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = Product::query()
            ->with(['createdByUser', 'updatedByUser'])
            ->when(! $actor->isGlobalAdmin(), fn (Builder $q) => $q->where('tenant_id', $actor->tenant_id))
            ->when($actor->isGlobalAdmin() && isset($filters['tenant_id']), fn (Builder $q) => $q->where('tenant_id', (int) $filters['tenant_id']))
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', (int) $filters['status']))
            ->when(isset($filters['search']), function (Builder $q) use ($filters): void {
                $search = (string) $filters['search'];
                $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            });

        $this->applyVisibilityScope($query, $actor);

        return $query->orderByDesc('id')->paginate($perPage);
    }

    public function findById(int $id): ?Product
    {
        return Product::query()->with(['createdByUser', 'updatedByUser'])->find($id);
    }

    public function create(array $data): Product
    {
        return Product::query()->create($data);
    }

    public function update(Product $product, array $data): Product
    {
        $product->update($data);

        return $product->refresh();
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }

    public function skuExistsForTenant(int $tenantId, string $sku, ?int $ignoreProductId = null): bool
    {
        return Product::query()
            ->where('tenant_id', $tenantId)
            ->where('sku', $sku)
            ->when($ignoreProductId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreProductId))
            ->exists();
    }

    private function applyVisibilityScope(Builder $query, User $actor): void
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return;
        }

        $query->where(function (Builder $inner) use ($actor): void {
            $inner->where('created_by_user_id', $actor->id);

            if ((int) $actor->data_scope !== DomainConstants::DATA_SCOPE_TEAM || $actor->team_id === null) {
                return;
            }

            $teamUserIds = User::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('team_id', $actor->team_id)
                ->pluck('id')
                ->all();

            if ($teamUserIds !== []) {
                $inner->orWhereIn('created_by_user_id', $teamUserIds);
            }
        });
    }
}
