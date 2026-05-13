<?php

namespace App\Http\Resources;

use App\Models\PartnerLead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PartnerLead */
class PartnerLeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'partner_organization_id' => $this->partner_organization_id,
            'title' => $this->title,
            'contact_email' => $this->contact_email,
            'contact_first_name' => $this->contact_first_name,
            'contact_last_name' => $this->contact_last_name,
            'company_name' => $this->company_name,
            'phone' => $this->phone,
            'description' => $this->description,
            'status' => $this->status,
            'approval_status' => $this->approval_status,
            'assigned_user_id' => $this->assigned_user_id,
            'metadata' => $this->metadata ?? (object) [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
