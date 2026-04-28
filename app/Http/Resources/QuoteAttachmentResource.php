<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\QuoteAttachment */
class QuoteAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'file_type' => $this->file_type,
            'file_size' => $this->file_size,
            'signed_url' => $this->when(isset($this->signed_url), fn () => $this->signed_url),
            'uploaded_by_user' => $this->whenLoaded('uploadedByUser', fn () => [
                'id' => $this->uploadedByUser?->id,
                'name' => $this->uploadedByUser?->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
