<?php

namespace App\Repositories;

use App\Models\PipelineStage;
use Illuminate\Support\Collection;

class PipelineStageRepository
{
    public function listByPipeline(int $pipelineId): Collection
    {
        return PipelineStage::query()
            ->where('pipeline_id', $pipelineId)
            ->orderBy('stage_order')
            ->get();
    }

    public function findById(int $id): ?PipelineStage
    {
        return PipelineStage::query()->find($id);
    }

    public function create(array $data): PipelineStage
    {
        return PipelineStage::query()->create($data);
    }

    public function update(PipelineStage $stage, array $data): PipelineStage
    {
        $stage->update($data);

        return $stage->refresh();
    }
}
