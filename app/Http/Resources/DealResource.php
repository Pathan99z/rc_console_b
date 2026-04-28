<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Deal */
class DealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'estimated_value' => $this->estimated_value,
            'currency_code' => $this->currency_code,
            'probability' => $this->probability,
            'expected_close_date' => $this->expected_close_date,
            'status' => $this->statusLabel(),
            'meta' => $this->meta ?? (object) [],
            'contact' => $this->whenLoaded('contact', fn () => [
                'id' => $this->contact?->id,
                'first_name' => $this->contact?->first_name,
                'last_name' => $this->contact?->last_name,
                'email' => $this->contact?->email,
            ]),
            'company' => $this->whenLoaded('company', fn () => new CompanyResource($this->company)),
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner?->id,
                'name' => $this->owner?->name,
                'email' => $this->owner?->email,
            ]),
            'pipeline' => $this->whenLoaded('pipeline', fn () => new PipelineResource($this->pipeline)),
            'stage' => $this->whenLoaded('stage', fn () => new PipelineStageResource($this->stage)),
            'histories' => $this->whenLoaded('histories', fn () => DealHistoryResource::collection($this->histories)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
