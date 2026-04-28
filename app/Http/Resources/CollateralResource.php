<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Collateral */
class CollateralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'product_id' => $this->product_id,
            'name' => $this->name,
            'type' => $this->type,
            'file_type' => $this->file_type,
            'file_size' => $this->file_size,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product?->id,
                'name' => $this->product?->name,
            ]),
            'created_by_user' => $this->whenLoaded('createdByUser', fn () => [
                'id' => $this->createdByUser?->id,
                'name' => $this->createdByUser?->name,
                'email' => $this->createdByUser?->email,
            ]),
            'signed_url' => $this->when(isset($this->signed_url), fn () => $this->signed_url),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
