<?php

namespace App\Http\Controllers\Api\Prm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Prm\ListPartnerResourceCollateralsRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Prm\PrmResourceCenterService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceCenterController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PrmResourceCenterService $resourceCenterService) {}

    public function index(ListPartnerResourceCollateralsRequest $request): JsonResponse
    {
        $items = $this->resourceCenterService->listForPartner(
            $request->user(),
            $request->validated(),
            (int) ($request->validated('per_page') ?? 15)
        );

        return $this->successResponse(DomainConstants::MSG_PRM_RESOURCE_FETCHED, [
            'items' => collect($items->items())->map(
                fn ($c) => $this->resourceCenterService->partnerResourcePayload($c)
            ),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function recordDownload(Request $request, int $collateralId): JsonResponse
    {
        $this->resourceCenterService->recordDownload(
            $request->user(),
            $collateralId,
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_PRM_DOWNLOAD_RECORDED);
    }
}
