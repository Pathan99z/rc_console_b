<?php

namespace App\Http\Controllers\Api\Prm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\LicenseEntitlement;
use App\Models\Organization;
use App\Models\Product;
use App\Services\Prm\LicenseEntitlementService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LicenseEntitlementController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly LicenseEntitlementService $licenseEntitlementService) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->licenseEntitlementService->list($request->user(), (int) ($request->input('per_page', 15)));

        return $this->successResponse(DomainConstants::MSG_PRM_LICENSE_FETCHED, [
            'items' => collect($items->items())->map(fn (LicenseEntitlement $r) => $this->entitlementListItem($r)),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'holder_organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'parent_entitlement_id' => ['nullable', 'integer', 'exists:license_entitlements,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'units_total' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $row = $this->licenseEntitlementService->allocate($request->user(), $data, $request->ip(), $request->userAgent());
        $row->loadMissing(['holderOrganization', 'product']);

        return $this->successResponse(DomainConstants::MSG_PRM_LICENSE_ALLOCATED, [
            'entitlement' => $this->entitlementAllocatedPayload($row),
        ], 201);
    }

    public function consume(Request $request, int $entitlementId): JsonResponse
    {
        $data = $request->validate(['units' => ['required', 'integer', 'min:1']]);
        $row = $this->licenseEntitlementService->consume(
            $request->user(),
            $entitlementId,
            (int) $data['units'],
            $request->ip(),
            $request->userAgent()
        );

        $row->loadMissing(['holderOrganization', 'product']);

        return $this->successResponse(DomainConstants::MSG_PRM_LICENSE_CONSUMED, [
            'entitlement' => $this->entitlementConsumedPayload($row),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function entitlementListItem(LicenseEntitlement $row): array
    {
        $holder = $row->relationLoaded('holderOrganization') ? $row->getRelation('holderOrganization') : $row->holderOrganization;
        $product = $row->relationLoaded('product') ? $row->getRelation('product') : $row->product;

        return [
            'id' => $row->id,
            'holder_organization_id' => $row->holder_organization_id,
            'product_id' => $row->product_id,
            'units_total' => $row->units_total,
            'units_consumed' => $row->units_consumed,
            'units_available' => (int) $row->units_total - (int) $row->units_consumed,
            'holder_organization' => $this->licenseHolderOrganizationSummary($holder),
            'product' => $this->licenseProductSummary($product),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entitlementAllocatedPayload(LicenseEntitlement $row): array
    {
        $holder = $row->relationLoaded('holderOrganization') ? $row->getRelation('holderOrganization') : $row->holderOrganization;
        $product = $row->relationLoaded('product') ? $row->getRelation('product') : $row->product;

        return [
            'id' => $row->id,
            'holder_organization_id' => $row->holder_organization_id,
            'units_total' => $row->units_total,
            'product_id' => $row->product_id,
            'holder_organization' => $this->licenseHolderOrganizationSummary($holder),
            'product' => $this->licenseProductSummary($product),
        ];
    }

    /**
     * Same shape as list rows; superset of the historical consume payload (id, units_consumed, units_total).
     *
     * @return array<string, mixed>
     */
    private function entitlementConsumedPayload(LicenseEntitlement $row): array
    {
        return $this->entitlementListItem($row);
    }

    /**
     * @return array{id: int, display_name: string|null, legal_name: string|null, type: string}|null
     */
    private function licenseHolderOrganizationSummary(?Organization $org): ?array
    {
        if (! $org instanceof Organization) {
            return null;
        }

        return [
            'id' => $org->id,
            'display_name' => $org->display_name,
            'legal_name' => $org->legal_name,
            'type' => $org->type,
        ];
    }

    /**
     * @return array{id: int, name: string, sku: string|null}|null
     */
    private function licenseProductSummary(?Product $product): ?array
    {
        if (! $product instanceof Product) {
            return null;
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
        ];
    }
}
