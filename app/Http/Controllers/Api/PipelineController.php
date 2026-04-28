<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pipeline\CreatePipelineRequest;
use App\Http\Requests\Pipeline\ListPipelinesRequest;
use App\Http\Requests\Pipeline\UpdatePipelineRequest;
use App\Http\Resources\PipelineResource;
use App\Http\Responses\ApiResponse;
use App\Services\Deal\DealManagementService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DealManagementService $service)
    {
    }

    public function index(ListPipelinesRequest $request): JsonResponse
    {
        $items = $this->service->listPipelines(
            $request->user(),
            $request->validated(),
            (int) ($request->validated('per_page') ?? 15)
        );

        return $this->successResponse(DomainConstants::MSG_PIPELINE_FETCHED, [
            'items' => PipelineResource::collection($items->items()),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(CreatePipelineRequest $request): JsonResponse
    {
        $pipeline = $this->service->createPipeline($request->user(), $request->validated());

        return $this->successResponse(DomainConstants::MSG_PIPELINE_CREATED, ['pipeline' => new PipelineResource($pipeline)], 201);
    }

    public function update(UpdatePipelineRequest $request, int $pipelineId): JsonResponse
    {
        $pipeline = $this->service->updatePipeline($request->user(), $pipelineId, $request->validated());

        return $this->successResponse(DomainConstants::MSG_PIPELINE_UPDATED, ['pipeline' => new PipelineResource($pipeline)]);
    }

    public function destroy(Request $request, int $pipelineId): JsonResponse
    {
        $this->service->deletePipeline($request->user(), $pipelineId);

        return $this->successResponse(DomainConstants::MSG_PIPELINE_DELETED);
    }
}
