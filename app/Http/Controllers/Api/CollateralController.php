<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Collateral\ListCollateralsRequest;
use App\Http\Requests\Collateral\SendCollateralRequest;
use App\Http\Requests\Collateral\UploadCollateralRequest;
use App\Http\Resources\CollateralResource;
use App\Http\Responses\ApiResponse;
use App\Services\Collateral\CollateralService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollateralController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CollateralService $service)
    {
    }

    public function index(ListCollateralsRequest $request): JsonResponse
    {
        $items = $this->service->listCollaterals(
            $request->user(),
            $request->validated(),
            (int) ($request->validated('per_page') ?? 15)
        );

        return $this->successResponse(DomainConstants::MSG_COLLATERAL_FETCHED, [
            'items' => CollateralResource::collection($items->items()),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(UploadCollateralRequest $request): JsonResponse
    {
        $collateral = $this->service->upload(
            $request->user(),
            $request->validated(),
            $request->file('file'),
            $request
        );

        return $this->successResponse(DomainConstants::MSG_COLLATERAL_UPLOADED, ['collateral' => new CollateralResource($collateral)], 201);
    }

    public function show(Request $request, int $collateralId): JsonResponse
    {
        $collateral = $this->service->getCollateral($request->user(), $collateralId);

        return $this->successResponse(DomainConstants::MSG_COLLATERAL_FETCHED, ['collateral' => new CollateralResource($collateral)]);
    }

    public function destroy(Request $request, int $collateralId): JsonResponse
    {
        $this->service->delete($request->user(), $collateralId, $request);

        return $this->successResponse(DomainConstants::MSG_COLLATERAL_DELETED);
    }

    public function send(SendCollateralRequest $request, int $collateralId): JsonResponse
    {
        $this->service->send($request->user(), $collateralId, $request->validated(), $request);

        return $this->successResponse(DomainConstants::MSG_COLLATERAL_SENT);
    }
}
