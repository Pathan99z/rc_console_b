<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Pipeline */
class PipelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'status' => (int) $this->status,
            'stages' => $this->whenLoaded('stages', fn () => PipelineStageResource::collection($this->stages)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
