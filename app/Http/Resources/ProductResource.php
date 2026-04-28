<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'description' => $this->description,
            'sku' => $this->sku,
            'unit_price' => $this->unit_price,
            'tax_rate' => $this->tax_rate,
            'status' => $this->statusLabel(),
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
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
