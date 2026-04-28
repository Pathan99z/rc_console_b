<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\CreateProductRequest;
use App\Http\Requests\Product\ListProductsRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Requests\Product\UpdateProductStatusRequest;
use App\Http\Resources\ProductResource;
use App\Http\Responses\ApiResponse;
use App\Services\Product\ProductService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductService $service)
    {
    }

    public function index(ListProductsRequest $request): JsonResponse
    {
        $items = $this->service->listProducts(
            $request->user(),
            $request->validated(),
            (int) ($request->validated('per_page') ?? 15)
        );

        return $this->successResponse(DomainConstants::MSG_PRODUCT_FETCHED, [
            'items' => ProductResource::collection($items->items()),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(CreateProductRequest $request): JsonResponse
    {
        $product = $this->service->createProduct($request->user(), $request->validated(), $request);

        return $this->successResponse(DomainConstants::MSG_PRODUCT_CREATED, ['product' => new ProductResource($product)], 201);
    }

    public function show(Request $request, int $productId): JsonResponse
    {
        $product = $this->service->getProduct($request->user(), $productId);

        return $this->successResponse(DomainConstants::MSG_PRODUCT_FETCHED, ['product' => new ProductResource($product)]);
    }

    public function update(UpdateProductRequest $request, int $productId): JsonResponse
    {
        $product = $this->service->updateProduct($request->user(), $productId, $request->validated(), $request);

        return $this->successResponse(DomainConstants::MSG_PRODUCT_UPDATED, ['product' => new ProductResource($product)]);
    }

    public function destroy(Request $request, int $productId): JsonResponse
    {
        $this->service->deleteProduct($request->user(), $productId, $request);

        return $this->successResponse(DomainConstants::MSG_PRODUCT_DELETED);
    }

    public function updateStatus(UpdateProductStatusRequest $request, int $productId): JsonResponse
    {
        $product = $this->service->updateStatus(
            $request->user(),
            $productId,
            (int) $request->validated('status'),
            $request
        );

        return $this->successResponse(DomainConstants::MSG_PRODUCT_STATUS_UPDATED, ['product' => new ProductResource($product)]);
    }
}
