<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Invoice */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'status' => $this->status,
            'tenant_id' => $this->tenant_id,
            'quote_id' => $this->quote_id,
            'payment_record_id' => $this->payment_record_id,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'discount_total' => $this->discount_total,
            'total' => $this->total,
            'currency_code' => $this->currency_code,
            'issued_at' => $this->issued_at,
            'paid_at' => $this->paid_at,
            'quote' => $this->whenLoaded('quote', fn () => [
                'id' => $this->quote?->id,
                'quote_number' => $this->quote?->quote_number,
                'status' => $this->quote?->statusLabel(),
                'payment_status' => $this->quote?->paymentStatusLabel(),
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
