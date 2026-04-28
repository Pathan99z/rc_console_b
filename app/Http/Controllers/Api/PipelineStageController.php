<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pipeline\CreatePipelineStageRequest;
use App\Http\Requests\Pipeline\UpdatePipelineStageRequest;
use App\Http\Resources\PipelineStageResource;
use App\Http\Responses\ApiResponse;
use App\Services\Deal\DealManagementService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineStageController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DealManagementService $service)
    {
    }

    public function index(Request $request, int $pipelineId): JsonResponse
    {
        $stages = $this->service->listStages($request->user(), $pipelineId);

        return $this->successResponse(DomainConstants::MSG_PIPELINE_STAGE_FETCHED, [
            'items' => PipelineStageResource::collection($stages),
        ]);
    }

    public function store(CreatePipelineStageRequest $request, int $pipelineId): JsonResponse
    {
        $stage = $this->service->createStage($request->user(), $pipelineId, $request->validated());

        return $this->successResponse(DomainConstants::MSG_PIPELINE_STAGE_CREATED, ['stage' => new PipelineStageResource($stage)], 201);
    }

    public function update(UpdatePipelineStageRequest $request, int $pipelineId, int $stageId): JsonResponse
    {
        $stage = $this->service->updateStage($request->user(), $pipelineId, $stageId, $request->validated());

        return $this->successResponse(DomainConstants::MSG_PIPELINE_STAGE_UPDATED, ['stage' => new PipelineStageResource($stage)]);
    }

    public function destroy(Request $request, int $pipelineId, int $stageId): JsonResponse
    {
        $this->service->deleteStage($request->user(), $pipelineId, $stageId);

        return $this->successResponse(DomainConstants::MSG_PIPELINE_STAGE_DELETED);
    }
}
