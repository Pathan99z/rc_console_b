<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Quote */
class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'quote_number' => $this->quote_number,
            'public_uuid' => $this->public_uuid,
            'status' => $this->statusLabel(),
            'payment_status' => $this->paymentStatusLabel(),
            'quote_type' => $this->quote_type,
            'notes' => $this->notes,
            'valid_until' => $this->valid_until,
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'discount_total' => $this->discount_total,
            'total' => $this->total,
            'currency_code' => $this->currency_code,
            'deal' => $this->whenLoaded('deal', fn () => [
                'id' => $this->deal?->id,
                'name' => $this->deal?->name,
                'status' => $this->deal?->statusLabel(),
            ]),
            'contact' => $this->whenLoaded('contact', fn () => [
                'id' => $this->contact?->id,
                'first_name' => $this->contact?->first_name,
                'last_name' => $this->contact?->last_name,
                'email' => $this->contact?->email,
                'company' => $this->contact?->company ? [
                    'id' => $this->contact->company->id,
                    'name' => $this->contact->company->name,
                ] : null,
            ]),
            'items' => $this->whenLoaded('items', fn () => QuoteItemResource::collection($this->items)),
            'attachments' => $this->whenLoaded('attachments', fn () => QuoteAttachmentResource::collection($this->attachments)),
            'created_by_user' => $this->whenLoaded('createdByUser', fn () => [
                'id' => $this->createdByUser?->id,
                'name' => $this->createdByUser?->name,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
