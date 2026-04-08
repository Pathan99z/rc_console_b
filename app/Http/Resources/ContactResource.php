<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Contact */
class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'lifecycle_stage' => $this->stageLabel(),
            'meta' => $this->meta ?? (object) [],
            'company' => $this->whenLoaded('company', fn () => new CompanyResource($this->company)),
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => [
                'id' => $this->assignedUser?->id,
                'name' => $this->assignedUser?->name,
                'email' => $this->assignedUser?->email,
            ]),
            'created_by_user' => $this->whenLoaded('createdByUser', fn () => [
                'id' => $this->createdByUser?->id,
                'name' => $this->createdByUser?->name,
                'email' => $this->createdByUser?->email,
            ]),
            'updated_by_user' => $this->whenLoaded('updatedByUser', fn () => [
                'id' => $this->updatedByUser?->id,
                'name' => $this->updatedByUser?->name,
                'email' => $this->updatedByUser?->email,
            ]),
            'activities' => $this->whenLoaded('activities', fn () => ContactActivityResource::collection($this->activities)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
